"""
SICMA — worker penyusun jadwal otomatis (OR-Tools CP-SAT).

Pemakaian:
    python solve.py input.json output.json

Masukan (dibuat SchedulerService di Laravel):
{
  "time_limit": 60,
  "days": [1, 2, 3, 4, 5],
  "slots": [{"label": "I", "start": 450, "end": 500}, ...],
  "rooms": [{"id": 1, "capacity": 45}, ...],            # capacity null = belum diketahui
  "leaders": [3],                                          # dosen pimpinan: tidak boleh hari Kamis (B11)
  "unavailable": [{"lecturer": 5, "day": 1, "start": 450, "end": 600}],
  "fixed": [{"day": 1, "start": 450, "end": 600, "room": 2, "lecturers": [1], "classes": [4], "phase": "full"}],
  "sessions": [{
      "id": 10, "duration": 150, "mode": "offline" | "online", "phase": "full" | "before_uts" | "after_uts",
      "lecturers": [1, 2], "classes": [4], "students": 40 | null,
      "locked": {"day": 1, "start": 450, "room": 2} | null,
      "preferred": {"day": 1, "start": 450, "room": 2} | null
  }]
}

Keluaran:
{"status": "OPTIMAL" | "FEASIBLE" | "INFEASIBLE" | "UNKNOWN" | "MODEL_INVALID",
 "objective": float | null, "wall_time": float,
 "assignments": [{"id": 10, "day": 1, "start_minute": 450, "end_minute": 600, "room_id": 2 | null}],
 "unplaced": [11], "message": "..."}

Aturan wajib: setiap sesi ditempatkan sekali; tidak ada bentrok ruang/dosen/kelas (interval setengah terbuka,
sesi sebelum & setelah UTS tidak saling bentrok); pimpinan tidak hari Kamis; online tidak memakai ruang;
kapasitas ruang cukup bila jumlah peserta & kapasitas diketahui; penempatan terkunci dan jadwal di luar
cakupan tetap. Tujuan: sedekat mungkin dengan penempatan sebelumnya (perubahan sesedikit mungkin).
"""

import json
import sys
import time

THURSDAY = 4
MAX_SLOT_GAP = 15  # menit jeda antarslot yang masih dianggap bersambung (mis. 15.30 → 15.40)


def worlds(phase):
    """Dunia waktu yang ditempati sesi: sesi satu semester menempati keduanya."""
    if phase == "before_uts":
        return ["before"]
    if phase == "after_uts":
        return ["after"]
    return ["before", "after"]


def candidate_starts(slots, duration):
    """Jam mulai yang mungkin: awal slot yang rangkaian slot bersambungnya cukup menampung durasi."""
    starts = []
    for i, slot in enumerate(slots):
        end_needed = slot["start"] + duration
        reach = slot["end"]
        j = i
        while reach < end_needed and j + 1 < len(slots) and slots[j + 1]["start"] - reach <= MAX_SLOT_GAP:
            j += 1
            reach = slots[j]["end"]
        if reach >= end_needed:
            starts.append(slot["start"])
    return starts


def overlaps(a_start, a_end, b_start, b_end):
    return a_start < b_end and b_start < a_end


def solve(data):
    from ortools.sat.python import cp_model

    started = time.time()
    days = data.get("days") or [1, 2, 3, 4, 5]
    slots = sorted(data.get("slots") or [], key=lambda s: s["start"])
    rooms = data.get("rooms") or []
    leaders = set(data.get("leaders") or [])
    sessions = data.get("sessions") or []
    fixed = data.get("fixed") or []
    unavailable = data.get("unavailable") or []

    if not slots or not sessions:
        return {"status": "MODEL_INVALID", "objective": None, "wall_time": 0.0, "assignments": [], "unplaced": [s["id"] for s in sessions],
                "message": "Slot jam atau sesi kosong."}

    # Titik waktu diskret: semua batas slot dan durasi kandidat.
    model = cp_model.CpModel()
    placements = {}  # session id -> list of (var, day, start, end, room)

    for session in sessions:
        duration = int(session["duration"])
        options = []
        locked = session.get("locked")
        is_online = session.get("mode") == "online"
        session_days = [d for d in days if not (d == THURSDAY and leaders.intersection(session.get("lecturers") or []))]

        if locked:
            session_days = [locked["day"]] if locked["day"] in days else []

        starts = candidate_starts(slots, duration)
        if locked:
            starts = [locked["start"]]

        room_choices = [None]
        if not is_online:
            room_choices = [r for r in rooms if r.get("capacity") is None or session.get("students") is None or r["capacity"] >= session["students"]]
            if locked and locked.get("room") is not None:
                room_choices = [r for r in rooms if r["id"] == locked["room"]]

        for day in session_days:
            for start in starts:
                end = start + duration
                if any(u["lecturer"] in (session.get("lecturers") or []) and u["day"] == day and overlaps(start, end, u["start"], u["end"]) for u in unavailable):
                    continue
                for room in room_choices:
                    room_id = None if room is None else room["id"]
                    # Bentrok dengan jadwal tetap di luar cakupan.
                    clash = False
                    for f in fixed:
                        if f["day"] != day or not overlaps(start, end, f["start"], f["end"]):
                            continue
                        if not set(worlds(session.get("phase"))).intersection(worlds(f.get("phase"))):
                            continue
                        if room_id is not None and f.get("room") == room_id:
                            clash = True
                        elif set(f.get("lecturers") or []).intersection(session.get("lecturers") or []):
                            clash = True
                        elif set(f.get("classes") or []).intersection(session.get("classes") or []):
                            clash = True
                        if clash:
                            break
                    if clash:
                        continue
                    var = model.NewBoolVar(f"x_{session['id']}_{day}_{start}_{room_id}")
                    options.append((var, day, start, end, room_id))

        placements[session["id"]] = options
        if not options:
            return {"status": "INFEASIBLE", "objective": None, "wall_time": round(time.time() - started, 3), "assignments": [],
                    "unplaced": [session["id"]], "message": f"Sesi {session['id']} tidak punya pilihan hari/jam/ruang yang memenuhi aturan."}
        model.AddExactlyOne(v for v, *_ in options)

    # Tidak ada bentrok antar sesi dalam cakupan: periksa pada setiap titik awal slot.
    points = sorted({s["start"] for s in slots} | {s["end"] - 1 for s in slots})
    by_session = {s["id"]: s for s in sessions}

    def add_capacity_constraints(key_fn):
        buckets = {}
        for sid, options in placements.items():
            session = by_session[sid]
            for var, day, start, end, room in options:
                for world in worlds(session.get("phase")):
                    for point in points:
                        if start <= point < end:
                            for key in key_fn(session, room):
                                buckets.setdefault((key, world, day, point), []).append(var)
        for variables in buckets.values():
            if len(variables) > 1:
                model.Add(sum(variables) <= 1)

    add_capacity_constraints(lambda s, room: [("room", room)] if room is not None else [])
    add_capacity_constraints(lambda s, room: [("lecturer", l) for l in (s.get("lecturers") or [])])
    add_capacity_constraints(lambda s, room: [("class", c) for c in (s.get("classes") or [])])

    # Tujuan: pertahankan penempatan sebelumnya sebisa mungkin.
    penalties = []
    for sid, options in placements.items():
        preferred = by_session[sid].get("preferred")
        for var, day, start, end, room in options:
            cost = 0
            if preferred:
                cost += 0 if preferred.get("day") == day else 6
                cost += 0 if preferred.get("start") == start else 3
                cost += 0 if preferred.get("room") == room else 2
            else:
                cost += day  # sesi baru: utamakan awal pekan agar hasil stabil
            if cost:
                penalties.append(cost * var)
    if penalties:
        model.Minimize(sum(penalties))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(data.get("time_limit", 60))
    solver.parameters.num_search_workers = 4
    status = solver.Solve(model)
    status_name = {
        cp_model.OPTIMAL: "OPTIMAL",
        cp_model.FEASIBLE: "FEASIBLE",
        cp_model.INFEASIBLE: "INFEASIBLE",
        cp_model.MODEL_INVALID: "MODEL_INVALID",
    }.get(status, "UNKNOWN")

    assignments = []
    if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        for sid, options in placements.items():
            for var, day, start, end, room in options:
                if solver.Value(var):
                    assignments.append({"id": sid, "day": day, "start_minute": start, "end_minute": end, "room_id": room})
                    break

    return {
        "status": status_name,
        "objective": solver.ObjectiveValue() if status in (cp_model.OPTIMAL, cp_model.FEASIBLE) else None,
        "wall_time": round(time.time() - started, 3),
        "assignments": assignments,
        "unplaced": [] if assignments else [s["id"] for s in sessions],
        "message": {
            "OPTIMAL": "Susunan terbaik ditemukan.",
            "FEASIBLE": "Susunan yang memenuhi aturan ditemukan, tetapi belum terbukti terbaik dalam batas waktu.",
            "INFEASIBLE": "Tidak ada susunan yang memenuhi semua aturan wajib.",
            "UNKNOWN": "Batas waktu pencarian tercapai sebelum susunan ditemukan.",
            "MODEL_INVALID": "Model penyusunan tidak valid.",
        }[status_name],
    }


def main():
    if len(sys.argv) != 3:
        print("Pemakaian: python solve.py input.json output.json", file=sys.stderr)
        return 2
    with open(sys.argv[1], encoding="utf-8") as handle:
        data = json.load(handle)
    try:
        result = solve(data)
    except Exception as exc:  # noqa: BLE001 — galat dilaporkan ke Laravel, bukan ditelan
        result = {"status": "MODEL_INVALID", "objective": None, "wall_time": 0.0, "assignments": [], "unplaced": [], "message": f"Worker gagal: {exc}"}
    tmp = sys.argv[2] + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        json.dump(result, handle)
    import os
    os.replace(tmp, sys.argv[2])  # tulis atomik agar Laravel tidak membaca file setengah jadi
    return 0


if __name__ == "__main__":
    sys.exit(main())
