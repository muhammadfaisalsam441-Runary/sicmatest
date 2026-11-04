figma.showUI(__html__, { width: 360, height: 300, themeColors: true });

interface Palette {
  primary: RGB;
  tint: RGB;
  bg: RGB;
  surface: RGB;
  ink: RGB;
  muted: RGB;
  border: RGB;
  success: RGB;
  successBg: RGB;
  successBorder: RGB;
  error: RGB;
  errorBg: RGB;
  errorBorder: RGB;
}

const hifiPalette: Palette = {
  primary: { r: 0.02, g: 0.153, b: 0.471 }, // #052778
  tint: { r: 0.906, g: 0.937, b: 1.0 }, // #E7EFFF
  bg: { r: 0.973, g: 0.976, b: 1.0 }, // #F8F9FF
  surface: { r: 1, g: 1, b: 1 }, // #FFFFFF
  ink: { r: 0.063, g: 0.133, b: 0.235 }, // #10223C
  muted: { r: 0.341, g: 0.38, b: 0.455 }, // #576174
  border: { r: 0.878, g: 0.902, b: 0.941 }, // #E0E6F0
  success: { r: 0.0, g: 0.404, b: 0.278 }, // #006747
  successBg: { r: 0.886, g: 0.965, b: 0.929 }, // #E2F6ED
  successBorder: { r: 0.72, g: 0.9, b: 0.82 }, // #B8E6D1
  error: { r: 0.663, g: 0.169, b: 0.192 }, // #A92B31
  errorBg: { r: 1.0, g: 0.941, b: 0.941 }, // #FFF0F0
  errorBorder: { r: 0.95, g: 0.78, b: 0.8 } // #F2C7CC
};

const lofiPalette: Palette = {
  primary: { r: 0.15, g: 0.15, b: 0.15 },
  tint: { r: 0.92, g: 0.92, b: 0.92 },
  bg: { r: 0.96, g: 0.96, b: 0.96 },
  surface: { r: 1, g: 1, b: 1 },
  ink: { r: 0.12, g: 0.12, b: 0.12 },
  muted: { r: 0.45, g: 0.45, b: 0.45 },
  border: { r: 0.84, g: 0.84, b: 0.84 },
  success: { r: 0.15, g: 0.15, b: 0.15 },
  successBg: { r: 0.95, g: 0.95, b: 0.95 },
  successBorder: { r: 0.82, g: 0.82, b: 0.82 },
  error: { r: 0.2, g: 0.2, b: 0.2 },
  errorBg: { r: 0.94, g: 0.94, b: 0.94 },
  errorBorder: { r: 0.78, g: 0.78, b: 0.78 }
};

let fontFamily = "Plus Jakarta Sans";

function resolveStyle(weight: string): string {
  if (fontFamily === "Inter" && weight === "SemiBold") return "Semi Bold";
  return weight;
}

function createText(text: string, size: number, weight: string, color: RGB): TextNode {
  const t = figma.createText();
  t.fontName = { family: fontFamily, style: resolveStyle(weight) };
  t.characters = text;
  t.fontSize = size;
  t.fills = [{ type: "SOLID", color }];
  return t;
}

function removeExistingByName(parent: FrameNode, name: string): void {
  const existing = parent.findAll((n) => n.name === name);
  for (const node of existing) {
    node.remove();
  }
}

function setVerticalAutoWidth(frame: FrameNode, width: number): void {
  frame.layoutMode = "VERTICAL";
  frame.counterAxisSizingMode = "FIXED";
  frame.resize(width, frame.height);
  frame.primaryAxisSizingMode = "AUTO";
}

function reflowVertical(parent: FrameNode, startY = 16, gap = 12, xPos = 16): void {
  if (parent.layoutMode !== "NONE") return;
  let y = startY;
  for (let i = 0; i < parent.children.length; i++) {
    const ch = parent.children[i];
    if (!ch.visible) continue;
    ch.x = xPos;
    ch.y = y;
    const extraGap = ch.type === "TEXT" && i === 0 ? 10 : gap;
    y += ch.height + extraGap;
  }
  const needed = Math.max(parent.height, y + 8);
  parent.resize(parent.width, needed);
}

// 9. Modern Bottom Sheet Search Filter Panel UX Builder
function buildSearchFilterPanel(panel: FrameNode, pal: Palette, isLoFi: boolean): void {
  while (panel.children.length > 0) {
    panel.children[0].remove();
  }

  panel.layoutMode = "VERTICAL";
  panel.primaryAxisSizingMode = "FIXED";
  panel.counterAxisSizingMode = "FIXED";
  panel.resize(390, 525);
  panel.x = 0;
  panel.y = 844 - 525; // 319
  panel.paddingLeft = 20;
  panel.paddingRight = 20;
  panel.paddingTop = 12;
  panel.paddingBottom = 20;
  panel.itemSpacing = 11;
  panel.topLeftRadius = 24;
  panel.topRightRadius = 24;
  panel.bottomLeftRadius = 0;
  panel.bottomRightRadius = 0;
  panel.fills = [{ type: "SOLID", color: pal.surface }];
  panel.strokes = [{ type: "SOLID", color: pal.border }];
  panel.strokeWeight = 1;

  // 1. Drag Handle
  const handleContainer = figma.createFrame();
  handleContainer.name = "Handle Container";
  handleContainer.layoutMode = "HORIZONTAL";
  handleContainer.primaryAxisAlignItems = "CENTER";
  handleContainer.counterAxisAlignItems = "CENTER";
  handleContainer.resize(350, 8);
  handleContainer.fills = [];
  const handle = figma.createFrame();
  handle.name = "Handle";
  handle.resize(40, 4);
  handle.cornerRadius = 2;
  handle.fills = [{ type: "SOLID", color: pal.border }];
  handleContainer.appendChild(handle);
  panel.appendChild(handleContainer);
  handleContainer.layoutSizingHorizontal = "FILL";

  // 2. Header (Title + Subtitle + Close Icon)
  const header = figma.createFrame();
  header.name = "Judul Panel Filter";
  header.layoutMode = "HORIZONTAL";
  header.primaryAxisAlignItems = "SPACE_BETWEEN";
  header.counterAxisAlignItems = "CENTER";
  header.resize(350, 38);
  header.fills = [];

  const headerInfo = figma.createFrame();
  headerInfo.name = "Info Header";
  headerInfo.layoutMode = "VERTICAL";
  headerInfo.itemSpacing = 2;
  headerInfo.fills = [];
  const tTitle = createText("Atur Pencarian", 17, "SemiBold", pal.ink);
  const tSub = createText("Sesuaikan waktu, durasi & kriteria ruangan", 11, "Regular", pal.muted);
  headerInfo.appendChild(tTitle);
  headerInfo.appendChild(tSub);

  const btnClose = figma.createFrame();
  btnClose.name = "Tombol Tutup";
  btnClose.layoutMode = "HORIZONTAL";
  btnClose.primaryAxisAlignItems = "CENTER";
  btnClose.counterAxisAlignItems = "CENTER";
  btnClose.resize(28, 28);
  btnClose.cornerRadius = 14;
  btnClose.fills = [{ type: "SOLID", color: pal.bg }];
  const tClose = createText("✕", 11, "SemiBold", pal.muted);
  btnClose.appendChild(tClose);

  header.appendChild(headerInfo);
  header.appendChild(btnClose);
  panel.appendChild(header);
  header.layoutSizingHorizontal = "FILL";

  function createSectionLabel(text: string): TextNode {
    return createText(text, 11, "SemiBold", pal.ink);
  }

  function createChip(text: string, isActive: boolean, isTint = false): FrameNode {
    const chip = figma.createFrame();
    chip.name = `Chip / ${text}`;
    chip.layoutMode = "HORIZONTAL";
    chip.primaryAxisAlignItems = "CENTER";
    chip.counterAxisAlignItems = "CENTER";
    chip.resize(80, 34);
    chip.cornerRadius = 8;
    chip.paddingLeft = 10;
    chip.paddingRight = 10;

    if (isActive) {
      chip.fills = [{ type: "SOLID", color: pal.primary }];
      chip.strokes = [];
      chip.appendChild(createText(text, 12, "SemiBold", pal.surface));
    } else if (isTint) {
      chip.fills = [{ type: "SOLID", color: pal.tint }];
      chip.strokes = [{ type: "SOLID", color: isLoFi ? pal.border : pal.primary }];
      chip.strokeWeight = 1;
      chip.appendChild(createText(text, 11, "SemiBold", pal.primary));
    } else {
      chip.fills = [{ type: "SOLID", color: pal.bg }];
      chip.strokes = [{ type: "SOLID", color: pal.border }];
      chip.strokeWeight = 1;
      chip.appendChild(createText(text, 12, "Medium", pal.ink));
    }
    return chip;
  }

  // 3. Baris Waktu (Tanggal & Waktu Mulai - 2 Kolom)
  const rowWaktu = figma.createFrame();
  rowWaktu.name = "Pilihan Tanggal & Waktu";
  rowWaktu.layoutMode = "HORIZONTAL";
  rowWaktu.itemSpacing = 10;
  rowWaktu.resize(350, 58);
  rowWaktu.fills = [];

  // Col Tanggal
  const colDate = figma.createFrame();
  colDate.name = "Kolom Tanggal";
  colDate.layoutMode = "VERTICAL";
  colDate.itemSpacing = 4;
  colDate.fills = [];
  colDate.appendChild(createSectionLabel("Tanggal"));
  const boxDate = figma.createFrame();
  boxDate.name = "Picker Tanggal";
  boxDate.layoutMode = "HORIZONTAL";
  boxDate.primaryAxisAlignItems = "SPACE_BETWEEN";
  boxDate.counterAxisAlignItems = "CENTER";
  boxDate.resize(170, 38);
  boxDate.cornerRadius = 8;
  boxDate.paddingLeft = 10;
  boxDate.paddingRight = 10;
  boxDate.fills = [{ type: "SOLID", color: pal.bg }];
  boxDate.strokes = [{ type: "SOLID", color: pal.border }];
  boxDate.strokeWeight = 1;
  boxDate.appendChild(createText("📅 2 Okt 2026", 12, "Medium", pal.ink));
  boxDate.appendChild(createText("▾", 11, "Regular", pal.muted));
  colDate.appendChild(boxDate);
  boxDate.layoutSizingHorizontal = "FILL";

  // Col Jam
  const colTime = figma.createFrame();
  colTime.name = "Kolom Jam Mulai";
  colTime.layoutMode = "VERTICAL";
  colTime.itemSpacing = 4;
  colTime.fills = [];
  colTime.appendChild(createSectionLabel("Waktu Mulai"));
  const boxTime = figma.createFrame();
  boxTime.name = "Picker Waktu";
  boxTime.layoutMode = "HORIZONTAL";
  boxTime.primaryAxisAlignItems = "SPACE_BETWEEN";
  boxTime.counterAxisAlignItems = "CENTER";
  boxTime.resize(170, 38);
  boxTime.cornerRadius = 8;
  boxTime.paddingLeft = 10;
  boxTime.paddingRight = 10;
  boxTime.fills = [{ type: "SOLID", color: pal.bg }];
  boxTime.strokes = [{ type: "SOLID", color: pal.border }];
  boxTime.strokeWeight = 1;
  boxTime.appendChild(createText("🕒 14:15 WITA", 12, "Medium", pal.ink));
  boxTime.appendChild(createText("▾", 11, "Regular", pal.muted));
  colTime.appendChild(boxTime);
  boxTime.layoutSizingHorizontal = "FILL";

  rowWaktu.appendChild(colDate);
  colDate.layoutSizingHorizontal = "FILL";
  rowWaktu.appendChild(colTime);
  colTime.layoutSizingHorizontal = "FILL";
  panel.appendChild(rowWaktu);
  rowWaktu.layoutSizingHorizontal = "FILL";

  // 4. Durasi Pemakaian (4 Chips)
  const gDurasi = figma.createFrame();
  gDurasi.name = "Grup Durasi";
  gDurasi.layoutMode = "VERTICAL";
  gDurasi.itemSpacing = 5;
  gDurasi.fills = [];
  gDurasi.appendChild(createSectionLabel("Durasi Pemakaian"));
  const chipsDurasi = figma.createFrame();
  chipsDurasi.name = "Chips Durasi";
  chipsDurasi.layoutMode = "HORIZONTAL";
  chipsDurasi.itemSpacing = 8;
  chipsDurasi.fills = [];
  for (const item of [
    { text: "1 Jam", active: true },
    { text: "2 Jam", active: false },
    { text: "3 Jam", active: false },
    { text: "≥ 4 Jam", active: false }
  ]) {
    const c = createChip(item.text, item.active);
    chipsDurasi.appendChild(c);
    c.layoutSizingHorizontal = "FILL";
  }
  gDurasi.appendChild(chipsDurasi);
  chipsDurasi.layoutSizingHorizontal = "FILL";
  panel.appendChild(gDurasi);
  gDurasi.layoutSizingHorizontal = "FILL";

  // 5. Lokasi Gedung (3 Chips)
  const gGedung = figma.createFrame();
  gGedung.name = "Grup Gedung";
  gGedung.layoutMode = "VERTICAL";
  gGedung.itemSpacing = 5;
  gGedung.fills = [];
  gGedung.appendChild(createSectionLabel("Lokasi Gedung"));
  const chipsGedung = figma.createFrame();
  chipsGedung.name = "Chips Gedung";
  chipsGedung.layoutMode = "HORIZONTAL";
  chipsGedung.itemSpacing = 8;
  chipsGedung.fills = [];
  for (const item of [
    { text: "Semua Gedung", active: true },
    { text: "Gedung AE", active: false },
    { text: "Menara Pinisi", active: false }
  ]) {
    const c = createChip(item.text, item.active);
    chipsGedung.appendChild(c);
    c.layoutSizingHorizontal = "FILL";
  }
  gGedung.appendChild(chipsGedung);
  chipsGedung.layoutSizingHorizontal = "FILL";
  panel.appendChild(gGedung);
  gGedung.layoutSizingHorizontal = "FILL";

  // 6. Kapasitas Ruangan (4 Chips)
  const gKapasitas = figma.createFrame();
  gKapasitas.name = "Grup Kapasitas";
  gKapasitas.layoutMode = "VERTICAL";
  gKapasitas.itemSpacing = 5;
  gKapasitas.fills = [];
  gKapasitas.appendChild(createSectionLabel("Kapasitas"));
  const chipsKapasitas = figma.createFrame();
  chipsKapasitas.name = "Chips Kapasitas";
  chipsKapasitas.layoutMode = "HORIZONTAL";
  chipsKapasitas.itemSpacing = 8;
  chipsKapasitas.fills = [];
  for (const item of [
    { text: "Bebas", active: true },
    { text: "< 15 org", active: false },
    { text: "15–30 org", active: false },
    { text: "> 30 org", active: false }
  ]) {
    const c = createChip(item.text, item.active);
    chipsKapasitas.appendChild(c);
    c.layoutSizingHorizontal = "FILL";
  }
  gKapasitas.appendChild(chipsKapasitas);
  chipsKapasitas.layoutSizingHorizontal = "FILL";
  panel.appendChild(gKapasitas);
  gKapasitas.layoutSizingHorizontal = "FILL";

  // 7. Fasilitas Kunci (Opsional - 3 Chips)
  const gFasilitas = figma.createFrame();
  gFasilitas.name = "Grup Fasilitas";
  gFasilitas.layoutMode = "VERTICAL";
  gFasilitas.itemSpacing = 5;
  gFasilitas.fills = [];
  gFasilitas.appendChild(createSectionLabel("Fasilitas Kunci (Opsional)"));
  const chipsFasilitas = figma.createFrame();
  chipsFasilitas.name = "Chips Fasilitas";
  chipsFasilitas.layoutMode = "HORIZONTAL";
  chipsFasilitas.itemSpacing = 8;
  chipsFasilitas.fills = [];
  chipsFasilitas.appendChild(createChip("✓ Proyektor", false, true));
  chipsFasilitas.appendChild(createChip("Lab Komputer", false, false));
  chipsFasilitas.appendChild(createChip("AC Dingin", false, false));
  for (const ch of chipsFasilitas.children) {
    (ch as FrameNode).layoutSizingHorizontal = "FILL";
  }
  gFasilitas.appendChild(chipsFasilitas);
  chipsFasilitas.layoutSizingHorizontal = "FILL";
  panel.appendChild(gFasilitas);
  gFasilitas.layoutSizingHorizontal = "FILL";

  // 8. Footer Aksi: Reset & Terapkan (4 Ruangan)
  const aksiFooter = figma.createFrame();
  aksiFooter.name = "Aksi Filter Footer";
  aksiFooter.layoutMode = "HORIZONTAL";
  aksiFooter.itemSpacing = 10;
  aksiFooter.resize(350, 44);
  aksiFooter.fills = [];

  const btnReset = figma.createFrame();
  btnReset.name = "Tombol Reset";
  btnReset.layoutMode = "HORIZONTAL";
  btnReset.primaryAxisAlignItems = "CENTER";
  btnReset.counterAxisAlignItems = "CENTER";
  btnReset.resize(96, 44);
  btnReset.cornerRadius = 12;
  btnReset.fills = [{ type: "SOLID", color: pal.tint }];
  btnReset.strokes = [];
  btnReset.appendChild(createText("Reset", 13, "SemiBold", pal.primary));

  const btnApply = figma.createFrame();
  btnApply.name = "Tombol Terapkan";
  btnApply.layoutMode = "HORIZONTAL";
  btnApply.primaryAxisAlignItems = "CENTER";
  btnApply.counterAxisAlignItems = "CENTER";
  btnApply.resize(244, 44);
  btnApply.cornerRadius = 12;
  btnApply.fills = [{ type: "SOLID", color: pal.primary }];
  btnApply.strokes = [];
  btnApply.appendChild(createText("Terapkan (4 Ruangan)", 13, "SemiBold", pal.surface));

  aksiFooter.appendChild(btnReset);
  aksiFooter.appendChild(btnApply);
  btnApply.layoutSizingHorizontal = "FILL";
  panel.appendChild(aksiFooter);
  aksiFooter.layoutSizingHorizontal = "FILL";
}

// 10. Ruangan JTIK Filter Sheet Contrast & Hierarchy Polishing
function improveJtikFilter(filterFrame: FrameNode, pal: Palette): void {
  function styleChip(node: SceneNode, isActive: boolean) {
    if (node.type === "FRAME" || node.type === "INSTANCE" || node.type === "COMPONENT") {
      node.fills = [{ type: "SOLID", color: isActive ? pal.primary : pal.bg }];
      node.strokes = isActive ? [] : [{ type: "SOLID", color: pal.border }];
      node.strokeWeight = 1;
      node.cornerRadius = 8;
      const t = node.findOne((n) => n.type === "TEXT") as TextNode | null;
      if (t) {
        t.fills = [{ type: "SOLID", color: isActive ? pal.surface : pal.ink }];
        t.fontName = { family: fontFamily, style: resolveStyle(isActive ? "SemiBold" : "Medium") };
      }
    }
  }

  // Gedung
  const pGedung = filterFrame.findOne((n) => n.name === "Pilihan Gedung");
  if (pGedung && "children" in pGedung) {
    for (const ch of pGedung.children) {
      const isSemua = ch.name.includes("Semua gedung");
      styleChip(ch, isSemua);
    }
  }

  // Lantai
  const pLantai = filterFrame.findOne((n) => n.name === "Pilihan Lantai");
  if (pLantai && "children" in pLantai) {
    for (const ch of pLantai.children) {
      const isSemua = ch.name.includes("Semua lantai");
      styleChip(ch, isSemua);
    }
  }

  // Status
  const pStatus = filterFrame.findOne((n) => n.name === "Pilihan Status");
  if (pStatus && "children" in pStatus) {
    for (const ch of pStatus.children) {
      const isSemua = ch.name.includes("Semua status");
      styleChip(ch, isSemua);
    }
  }

  // Sub-labels ("Gedung terpilih", "Lantai terpilih", "Status terpilih")
  for (const name of ["Gedung terpilih", "Lantai terpilih", "Status terpilih"]) {
    const lbl = filterFrame.findOne((n) => n.name === name);
    if (lbl && lbl.type === "TEXT") {
      lbl.fills = [{ type: "SOLID", color: pal.muted }];
    }
  }

  // Buttons Aksi filter
  const aksiFilter = filterFrame.findOne((n) => n.name === "Aksi filter");
  if (aksiFilter && (aksiFilter.type === "FRAME" || aksiFilter.type === "INSTANCE")) {
    const btnReset = aksiFilter.findOne((n) => n.name.includes("Reset"));
    if (btnReset && (btnReset.type === "FRAME" || btnReset.type === "INSTANCE")) {
      btnReset.fills = [{ type: "SOLID", color: pal.tint }];
      btnReset.strokes = [];
      const t = btnReset.findOne((n) => n.type === "TEXT") as TextNode | null;
      if (t) {
        t.fills = [{ type: "SOLID", color: pal.primary }];
        t.fontName = { family: fontFamily, style: resolveStyle("SemiBold") };
      }
    }
    const btnTerapkan = aksiFilter.findOne((n) => n.name.includes("Terapkan"));
    if (btnTerapkan && (btnTerapkan.type === "FRAME" || btnTerapkan.type === "INSTANCE")) {
      btnTerapkan.fills = [{ type: "SOLID", color: pal.primary }];
      btnTerapkan.strokes = [];
      const t = btnTerapkan.findOne((n) => n.type === "TEXT") as TextNode | null;
      if (t) {
        t.characters = "Terapkan (24 Ruangan)";
        t.fills = [{ type: "SOLID", color: pal.surface }];
        t.fontName = { family: fontFamily, style: resolveStyle("SemiBold") };
      }
    }
  }

  // Tutup filter button
  const btnTutup = filterFrame.findOne((n) => n.name.includes("Tutup filter"));
  if (btnTutup && (btnTutup.type === "FRAME" || btnTutup.type === "INSTANCE")) {
    btnTutup.fills = [{ type: "SOLID", color: pal.bg }];
    btnTutup.strokes = [{ type: "SOLID", color: pal.border }];
    btnTutup.strokeWeight = 1;
    const t = btnTutup.findOne((n) => n.type === "TEXT") as TextNode | null;
    if (t) {
      t.fills = [{ type: "SOLID", color: pal.muted }];
      t.fontName = { family: fontFamily, style: resolveStyle("Medium") };
    }
  }
}

interface PageTargets {
  pageId: string;
  palette: Palette;
  isLoFi: boolean;
  loginFailId: string;
  profileId: string;
  detailIds: string[];
  bookedIds: string[];
  useIds: string[];
  doneIds: string[];
  failBookingId: string;
  rJtikId: string;
  searchFilterPanelId: string;
  jtikFilterSheetId: string;
  truncatedTexts?: { id: string; fullText: string }[];
  floatingJtikIds?: { sectionId: string; ids: string[] };
}

async function applyPageFixes(targets: PageTargets): Promise<number> {
  const page = figma.root.children.find((p) => p.id === targets.pageId);
  if (!page) return 0;
  await page.loadAsync();

  const pal = targets.palette;
  let updatedScreens = 0;

  // 0. Perbaiki teks terpotong
  if (targets.truncatedTexts) {
    for (const item of targets.truncatedTexts) {
      const tNode = await figma.getNodeByIdAsync(item.id);
      if (tNode && tNode.type === "TEXT") {
        tNode.characters = item.fullText;
      }
    }
  }

  // 0b. Rapikan 3 frame mengambang di Lo-Fi Seksi 02 agar masuk ke dalam Section seperti di Hi-Fi
  if (targets.floatingJtikIds) {
    const sec02 = await figma.getNodeByIdAsync(targets.floatingJtikIds.sectionId);
    if (sec02 && sec02.type === "SECTION") {
      if (sec02.height < 4748) {
        sec02.resizeWithoutConstraints(sec02.width, 4748);
      }
      const yCoords = [1100, 1871, 2296];
      for (let i = 0; i < targets.floatingJtikIds.ids.length; i++) {
        const fn = await figma.getNodeByIdAsync(targets.floatingJtikIds.ids[i]);
        if (fn && fn.type === "FRAME") {
          if (fn.parent?.id !== sec02.id) {
            sec02.appendChild(fn);
          }
          fn.x = 1004;
          fn.y = yCoords[i];
        }
      }
    }
  }

  // 1. Login Gagal — Pastikan kotak error tidak memotong teks "admin."
  const loginFail = await figma.getNodeByIdAsync(targets.loginFailId);
  if (loginFail && loginFail.type === "FRAME") {
    loginFail.name = "Login / Gagal (Kredensial Keliru)";
    const formBox = loginFail.findOne((n) => n.name === "Pencarian dan hasil" && n.type === "FRAME") as FrameNode | null;
    if (formBox) {
      removeExistingByName(formBox, "Peringatan Error");
      removeExistingByName(formBox, "Bantuan Sandi");

      const errCard = figma.createFrame();
      errCard.name = "Peringatan Error";
      errCard.itemSpacing = 4;
      errCard.paddingLeft = 14;
      errCard.paddingRight = 14;
      errCard.paddingTop = 12;
      errCard.paddingBottom = 12;
      errCard.cornerRadius = 12;
      errCard.fills = [{ type: "SOLID", color: pal.errorBg }];
      errCard.strokes = [{ type: "SOLID", color: pal.errorBorder }];
      errCard.strokeWeight = 1;
      setVerticalAutoWidth(errCard, 358);

      const errTitle = createText("NIM atau kata sandi tidak cocok", 13, "SemiBold", pal.error);
      const errDesc = createText("Silakan periksa kembali atau hubungi pengurus kelas/admin.", 12, "Regular", pal.error);
      errCard.appendChild(errTitle);
      errTitle.layoutSizingHorizontal = "FILL";
      errCard.appendChild(errDesc);
      errDesc.layoutSizingHorizontal = "FILL";
      setVerticalAutoWidth(errCard, 358);

      formBox.insertChild(2, errCard);

      const help = createText("Lupa kata sandi? Hubungi Staf JTIK", 13, "SemiBold", pal.primary);
      help.name = "Bantuan Sandi";
      help.textDecoration = "UNDERLINE";
      formBox.appendChild(help);
      help.resize(358, help.height);

      reflowVertical(formBox, 16, 12, 16);
      updatedScreens++;
    }
  }

  // 2. Profil Pengguna — Pastikan tombol Logout tidak terpotong Navigasi bawah (y <= 690 < 716)
  const prof = await figma.getNodeByIdAsync(targets.profileId);
  if (prof && prof.type === "FRAME") {
    const content = prof.findOne((n) => n.name === "Konten" && n.type === "FRAME") as FrameNode | null;
    if (content) {
      removeExistingByName(content, "Aksi / Keluar Akun");

      // Rapikan jarak dalam kartu Informasi akun agar hemat 36px vertikal
      const infoAkun = content.findOne((n) => n.name === "Informasi akun" && n.type === "FRAME") as FrameNode | null;
      if (infoAkun && infoAkun.layoutMode === "NONE") {
        let iy = 10;
        for (const ch of infoAkun.children) {
          ch.x = 12;
          ch.y = iy;
          iy += ch.height + 6;
        }
        infoAkun.resize(358, iy + 6);
      }

      const btnLogout = figma.createFrame();
      btnLogout.name = "Aksi / Keluar Akun";
      btnLogout.layoutMode = "HORIZONTAL";
      btnLogout.primaryAxisSizingMode = "FIXED";
      btnLogout.counterAxisSizingMode = "FIXED";
      btnLogout.primaryAxisAlignItems = "CENTER";
      btnLogout.counterAxisAlignItems = "CENTER";
      btnLogout.resize(358, 44);
      btnLogout.cornerRadius = 12;
      btnLogout.fills = [{ type: "SOLID", color: pal.errorBg }];
      btnLogout.strokes = [];
      btnLogout.appendChild(createText("Keluar dari Akun (Logout)", 14, "SemiBold", pal.error));
      content.appendChild(btnLogout);

      reflowVertical(content, 14, 10, 16);
      content.resize(390, 712);
      updatedScreens++;
    }
  }

  // 3. Detail Ruangan — Pastikan Riwayat penggunaan pertama tidak terpotong sticky bar Pesan Ruang (y=644)
  for (const id of targets.detailIds) {
    const df = await figma.getNodeByIdAsync(id);
    if (df && df.type === "FRAME") {
      const konten = df.findOne((n) => n.name === "Konten" && n.type === "FRAME") as FrameNode | null;
      if (konten) {
        removeExistingByName(konten, "Informasi Kunci Ruangan");

        const keyCard = figma.createFrame();
        keyCard.name = "Informasi Kunci Ruangan";
        keyCard.itemSpacing = 2;
        keyCard.paddingLeft = 12;
        keyCard.paddingRight = 12;
        keyCard.paddingTop = 8;
        keyCard.paddingBottom = 8;
        keyCard.cornerRadius = 12;
        keyCard.fills = [{ type: "SOLID", color: pal.tint }];
        keyCard.strokes = [];
        setVerticalAutoWidth(keyCard, 358);

        const kt1 = createText("Lokasi Kunci Fisik: Ruang Staf / OB (Lt. 1)", 13, "SemiBold", pal.primary);
        const kt2 = createText("Kunci tersedia • Ambil setelah memesan ruangan", 12, "Regular", pal.muted);
        keyCard.appendChild(kt1);
        kt1.layoutSizingHorizontal = "FILL";
        keyCard.appendChild(kt2);
        kt2.layoutSizingHorizontal = "FILL";
        setVerticalAutoWidth(keyCard, 358);

        konten.insertChild(4, keyCard);
        reflowVertical(konten, 12, 9, 16);

        const riwayatNodes = konten.children.filter((c) => c.name.startsWith("Riwayat /"));
        if (riwayatNodes.length >= 2) {
          const r2 = riwayatNodes[1];
          if (r2.y < 644 && r2.y + r2.height > 644) {
            r2.y = 648;
          }
        }
        updatedScreens++;
      }
    }
  }

  // 4. Dipesan — Kartu Info Kunci Fisik berlatar putih bersih di dalam kotak biru Instruksi mulai
  for (const id of targets.bookedIds) {
    const bf = await figma.getNodeByIdAsync(id);
    if (bf && bf.type === "FRAME") {
      const inst = bf.findOne((n) => n.name === "Instruksi mulai" && n.type === "FRAME") as FrameNode | null;
      const konten = bf.findOne((n) => n.name === "Konten" && n.type === "FRAME") as FrameNode | null;
      if (inst && konten) {
        removeExistingByName(inst, "Info Kunci Fisik");

        const keyInfo = figma.createFrame();
        keyInfo.name = "Info Kunci Fisik";
        keyInfo.itemSpacing = 2;
        keyInfo.paddingLeft = 12;
        keyInfo.paddingRight = 12;
        keyInfo.paddingTop = 8;
        keyInfo.paddingBottom = 8;
        keyInfo.cornerRadius = 8;
        keyInfo.fills = [{ type: "SOLID", color: pal.surface }];
        keyInfo.strokes = [{ type: "SOLID", color: pal.border }];
        keyInfo.strokeWeight = 1;
        setVerticalAutoWidth(keyInfo, 334);

        const tKunci = createText("Ambil Kunci di Ruang Staf / OB (Lt. 1)", 12, "SemiBold", pal.primary);
        const tKunciSub = createText("Tunjukkan halaman pesanan ini saat pengambilan kunci.", 11, "Regular", pal.muted);
        keyInfo.appendChild(tKunci);
        tKunci.layoutSizingHorizontal = "FILL";
        keyInfo.appendChild(tKunciSub);
        tKunciSub.layoutSizingHorizontal = "FILL";
        setVerticalAutoWidth(keyInfo, 334);

        inst.appendChild(keyInfo);
        keyInfo.x = 12;
        keyInfo.y = 63;
        inst.resize(358, 63 + keyInfo.height + 12);

        reflowVertical(konten, 16, 12, 16);
        updatedScreens++;
      }
    }
  }

  // 5. Digunakan — Tombol +30 Menit & Lapor Kendala tanpa border kasar, radius 12px
  for (const id of targets.useIds) {
    const uf = await figma.getNodeByIdAsync(id);
    if (uf && uf.type === "FRAME") {
      const k = uf.findOne((n) => n.name === "Konten" && n.type === "FRAME") as FrameNode | null;
      if (k) {
        removeExistingByName(k, "Aksi Tambahan Penggunaan");

        const extra = figma.createFrame();
        extra.name = "Aksi Tambahan Penggunaan";
        extra.layoutMode = "HORIZONTAL";
        extra.primaryAxisSizingMode = "FIXED";
        extra.counterAxisSizingMode = "AUTO";
        extra.resize(358, 44);
        extra.itemSpacing = 12;
        extra.fills = [];

        const bExt = figma.createFrame();
        bExt.name = "Aksi / Perpanjang Waktu";
        bExt.layoutMode = "HORIZONTAL";
        bExt.primaryAxisAlignItems = "CENTER";
        bExt.counterAxisAlignItems = "CENTER";
        bExt.resize(173, 44);
        bExt.cornerRadius = 12;
        bExt.fills = [{ type: "SOLID", color: pal.tint }];
        bExt.strokes = [];
        bExt.appendChild(createText("+30 Menit", 13, "SemiBold", pal.primary));

        const bRep = figma.createFrame();
        bRep.name = "Aksi / Lapor Fasilitas";
        bRep.layoutMode = "HORIZONTAL";
        bRep.primaryAxisAlignItems = "CENTER";
        bRep.counterAxisAlignItems = "CENTER";
        bRep.resize(173, 44);
        bRep.cornerRadius = 12;
        bRep.fills = [{ type: "SOLID", color: pal.errorBg }];
        bRep.strokes = [];
        bRep.appendChild(createText("Lapor Kendala", 13, "SemiBold", pal.error));

        extra.appendChild(bExt);
        bExt.layoutSizingHorizontal = "FILL";
        extra.appendChild(bRep);
        bRep.layoutSizingHorizontal = "FILL";

        k.insertChild(Math.max(0, k.children.length - 2), extra);
        reflowVertical(k, 16, 12, 16);
        updatedScreens++;
      }
    }
  }

  // 6. Selesai — Checklist Pengembalian lembut & pastikan tombol "Cari ruangan lagi" tampil utuh (< 716px)
  for (const id of targets.doneIds) {
    const df = await figma.getNodeByIdAsync(id);
    if (df && df.type === "FRAME") {
      const k = df.findOne((n) => n.name === "Konten" && n.type === "FRAME") as FrameNode | null;
      if (k) {
        removeExistingByName(k, "Checklist Pengembalian");

        const chkCard = figma.createFrame();
        chkCard.name = "Checklist Pengembalian";
        chkCard.itemSpacing = 5;
        chkCard.paddingLeft = 14;
        chkCard.paddingRight = 14;
        chkCard.paddingTop = 10;
        chkCard.paddingBottom = 10;
        chkCard.cornerRadius = 12;
        chkCard.fills = [{ type: "SOLID", color: pal.successBg }];
        chkCard.strokes = [{ type: "SOLID", color: pal.successBorder }];
        chkCard.strokeWeight = 1;
        setVerticalAutoWidth(chkCard, 358);

        const lines = [
          createText("Kepatuhan Fasilitas Selesai Digunakan:", 12, "SemiBold", pal.success),
          createText("✓ Matikan pendingin ruangan (AC) & proyektor", 11, "Regular", pal.ink),
          createText("✓ Rapikan kursi & pastikan sampah dibuang", 11, "Regular", pal.ink),
          createText("✓ Kunci pintu & kembalikan kunci ke Staf/OB", 11, "Regular", pal.ink)
        ];
        for (const line of lines) {
          chkCard.appendChild(line);
          line.layoutSizingHorizontal = "FILL";
        }
        setVerticalAutoWidth(chkCard, 358);

        k.insertChild(2, chkCard);

        const riwayatItems = k.children.filter((c) => c.name.startsWith("Riwayat /"));
        if (riwayatItems.length >= 3) {
          riwayatItems[2].visible = false;
        }

        reflowVertical(k, 14, 10, 16);
        k.resize(390, 712);
        updatedScreens++;
      }
    }
  }

  // 7. Pemesanan Gagal — Padding bawah kartu Rekomendasi Ruangan simetris (14px)
  const failF = await figma.getNodeByIdAsync(targets.failBookingId);
  if (failF && failF.type === "FRAME") {
    const container = failF.findOne((n) => n.name === "Pencarian dan hasil" && n.type === "FRAME") as FrameNode | null;
    if (container) {
      removeExistingByName(container, "Rekomendasi Ruangan");

      const recBox = figma.createFrame();
      recBox.name = "Rekomendasi Ruangan";
      recBox.itemSpacing = 8;
      recBox.paddingLeft = 14;
      recBox.paddingRight = 14;
      recBox.paddingTop = 14;
      recBox.paddingBottom = 14;
      recBox.cornerRadius = 12;
      recBox.fills = [{ type: "SOLID", color: pal.surface }];
      recBox.strokes = [{ type: "SOLID", color: pal.border }];
      recBox.strokeWeight = 1;
      setVerticalAutoWidth(recBox, 358);

      const rTitle = createText("Rekomendasi Ruang Kosong Lainnya:", 12, "SemiBold", pal.muted);
      const rRoom = createText("AE 106 • Gedung Kuliah AE Lt. 1", 15, "Bold", pal.primary);
      const rCap = createText("Kapasitas 45 kursi • Kosong s/d 17:30 WITA", 12, "Regular", pal.muted);

      const btnPick = figma.createFrame();
      btnPick.name = "Pilih Ruangan Ini";
      btnPick.layoutMode = "HORIZONTAL";
      btnPick.primaryAxisSizingMode = "FIXED";
      btnPick.counterAxisSizingMode = "FIXED";
      btnPick.primaryAxisAlignItems = "CENTER";
      btnPick.counterAxisAlignItems = "CENTER";
      btnPick.resize(330, 40);
      btnPick.cornerRadius = 10;
      btnPick.fills = [{ type: "SOLID", color: pal.tint }];
      btnPick.strokes = [];
      btnPick.appendChild(createText("Gunakan Ruang Ini", 13, "SemiBold", pal.primary));

      recBox.appendChild(rTitle);
      rTitle.layoutSizingHorizontal = "FILL";
      recBox.appendChild(rRoom);
      rRoom.layoutSizingHorizontal = "FILL";
      recBox.appendChild(rCap);
      rCap.layoutSizingHorizontal = "FILL";
      recBox.appendChild(btnPick);
      btnPick.layoutSizingHorizontal = "FILL";
      setVerticalAutoWidth(recBox, 358);

      container.insertChild(Math.max(0, container.children.length - 2), recBox);
      reflowVertical(container, 16, 12, 16);
      updatedScreens++;
    }
  }

  // 8. Ruangan JTIK — Toggle Daftar | Denah
  const rJtik = await figma.getNodeByIdAsync(targets.rJtikId);
  if (rJtik && rJtik.type === "FRAME") {
    const container = rJtik.findOne((n) => n.name === "Container" && n.type === "FRAME") as FrameNode | null;
    if (container) {
      removeExistingByName(container, "Toggle Tampilan");

      const toggle = figma.createFrame();
      toggle.name = "Toggle Tampilan";
      toggle.layoutMode = "HORIZONTAL";
      toggle.primaryAxisSizingMode = "FIXED";
      toggle.counterAxisSizingMode = "FIXED";
      toggle.resize(358, 42);
      toggle.itemSpacing = 4;
      toggle.paddingLeft = 4;
      toggle.paddingRight = 4;
      toggle.paddingTop = 4;
      toggle.paddingBottom = 4;
      toggle.cornerRadius = 10;
      toggle.fills = [{ type: "SOLID", color: pal.bg }];
      toggle.strokes = [{ type: "SOLID", color: pal.border }];
      toggle.strokeWeight = 1;

      const optList = figma.createFrame();
      optList.layoutMode = "HORIZONTAL";
      optList.primaryAxisAlignItems = "CENTER";
      optList.counterAxisAlignItems = "CENTER";
      optList.resize(173, 34);
      optList.cornerRadius = 8;
      optList.fills = [{ type: "SOLID", color: pal.tint }];
      optList.appendChild(createText("Daftar Ruangan", 12, "SemiBold", pal.primary));

      const optMap = figma.createFrame();
      optMap.layoutMode = "HORIZONTAL";
      optMap.primaryAxisAlignItems = "CENTER";
      optMap.counterAxisAlignItems = "CENTER";
      optMap.resize(173, 34);
      optMap.cornerRadius = 8;
      optMap.fills = [];
      optMap.appendChild(createText("Denah / Peta", 12, "Medium", pal.muted));

      toggle.appendChild(optList);
      optList.layoutSizingHorizontal = "FILL";
      toggle.appendChild(optMap);
      optMap.layoutSizingHorizontal = "FILL";

      container.insertChild(1, toggle);
      reflowVertical(container, 16, 12, 16);
      updatedScreens++;
    }
  }

  // 9. Panel Filter Pencarian — Modern Bottom Sheet UI/UX
  const searchFilter = await figma.getNodeByIdAsync(targets.searchFilterPanelId);
  if (searchFilter && searchFilter.type === "FRAME") {
    buildSearchFilterPanel(searchFilter, pal, targets.isLoFi);
    updatedScreens++;
  }

  // 10. Filter Sheet Ruangan JTIK — Contrast & Visual Polish
  const jtikFilter = await figma.getNodeByIdAsync(targets.jtikFilterSheetId);
  if (jtikFilter && jtikFilter.type === "FRAME") {
    improveJtikFilter(jtikFilter, pal);
    updatedScreens++;
  }

  return updatedScreens;
}

async function applyFixes() {
  try {
    try {
      await figma.loadFontAsync({ family: "Plus Jakarta Sans", style: "Regular" });
      await figma.loadFontAsync({ family: "Plus Jakarta Sans", style: "Medium" });
      await figma.loadFontAsync({ family: "Plus Jakarta Sans", style: "SemiBold" });
      await figma.loadFontAsync({ family: "Plus Jakarta Sans", style: "Bold" });
    } catch {
      fontFamily = "Inter";
      await figma.loadFontAsync({ family: "Inter", style: "Regular" });
      await figma.loadFontAsync({ family: "Inter", style: "Medium" });
      await figma.loadFontAsync({ family: "Inter", style: "Semi Bold" });
      await figma.loadFontAsync({ family: "Inter", style: "Bold" });
    }

    const hifiTargets: PageTargets = {
      pageId: "176:1842",
      palette: hifiPalette,
      isLoFi: false,
      loginFailId: "314:4562",
      profileId: "176:2104",
      detailIds: ["176:2140", "176:2175", "176:2210", "176:2245", "176:2279", "176:2314"],
      bookedIds: ["176:2348", "176:2427", "176:2506", "176:2585", "176:2662"],
      useIds: ["176:2375", "176:2454", "176:2533", "176:2612", "176:2689"],
      doneIds: ["176:2402", "176:2481", "176:2560", "176:2639", "176:2716"],
      failBookingId: "314:4582",
      rJtikId: "176:1878",
      searchFilterPanelId: "176:2063",
      jtikFilterSheetId: "820:4020",
      truncatedTexts: [
        { id: "176:2748", fullText: "Coba durasi lebih pendek atau jumlah orang lebih sedikit." },
        { id: "176:2787", fullText: "Riwayat muncul setelah kamu memesan atau menggunakan ruangan." }
      ]
    };

    const lofiTargets: PageTargets = {
      pageId: "150:1851",
      palette: lofiPalette,
      isLoFi: true,
      loginFailId: "318:2422",
      profileId: "150:2113",
      detailIds: ["150:2149", "150:2184", "150:2219", "150:2254", "150:2288", "150:2323"],
      bookedIds: ["150:2357", "150:2436", "150:2515", "150:2594", "150:2671"],
      useIds: ["150:2384", "150:2463", "150:2542", "150:2621", "150:2698"],
      doneIds: ["150:2411", "150:2490", "150:2569", "150:2648", "150:2725"],
      failBookingId: "318:2442",
      rJtikId: "150:1887",
      searchFilterPanelId: "150:2072",
      jtikFilterSheetId: "821:2016",
      floatingJtikIds: {
        sectionId: "150:1852",
        ids: ["821:2016", "821:2017", "821:2018"]
      }
    };

    const hifiPage = figma.root.children.find((p) => p.id === "176:1842" || p.name.includes("High Fidelity"));
    if (hifiPage && figma.currentPage.id !== hifiPage.id) {
      await figma.setCurrentPageAsync(hifiPage);
    }

    const cHifi = await applyPageFixes(hifiTargets);
    const cLofi = await applyPageFixes(lofiTargets);
    const total = cHifi + cLofi;

    figma.notify(`✅ Berhasil merapikan ${total} layar (termasuk Panel Filter & Katalog JTIK) pada Hi-Fi & Lo-Fi!`);
    figma.ui.postMessage({ type: "done", count: total });
  } catch (err: unknown) {
    const errorMessage = err instanceof Error ? err.message : String(err);
    figma.notify("❌ Gagal: " + errorMessage, { error: true });
    figma.ui.postMessage({ type: "error", message: errorMessage });
  }
}


// ==========================================
// ERD OPTIMIZATION ENGINE (v4)
// ==========================================

async function styleTextNode(
  tNode: TextNode,
  size: number,
  styleName: "Regular" | "Medium" | "SemiBold" | "Bold",
  color?: RGB
): Promise<void> {
  const family = "Inter";
  const mappedStyle = styleName === "SemiBold" ? "Semi Bold" : styleName;
  const targetFont: FontName = { family, style: mappedStyle };

  if (tNode.fontName !== figma.mixed) {
    try {
      await figma.loadFontAsync(tNode.fontName);
    } catch {
      // ignore font loading fallback
    }
  }

  try {
    await figma.loadFontAsync(targetFont);
    tNode.fontName = targetFont;
  } catch {
    // fallback if target font load fails
  }

  tNode.fontSize = size;
  if (color) {
    tNode.fills = [{ type: "SOLID", color }];
  }
}

async function formatErdTable(table: FrameNode): Promise<void> {
  // 1. Header: 'Nama tabel'
  const header = table.findOne((n) => n.name === "Nama tabel" && n.type === "FRAME") as FrameNode | null;
  if (header) {
    header.resize(table.width, 72);
    const textChildren = header.children.filter((c) => c.type === "TEXT") as TextNode[];
    if (textChildren.length >= 1) {
      await styleTextNode(textChildren[0], 20, "Bold", { r: 0.05, g: 0.1, b: 0.22 });
      textChildren[0].x = 16;
      textChildren[0].y = 10;
    }
    if (textChildren.length >= 2) {
      await styleTextNode(textChildren[1], 13, "Regular", { r: 0.38, g: 0.42, b: 0.5 });
      textChildren[1].x = 16;
      textChildren[1].y = 40;
    }
  }

  // 2. Attributes & Keys: 'Atribut dan kunci'
  const attrFrame = table.findOne((n) => n.name === "Atribut dan kunci" && n.type === "FRAME") as FrameNode | null;
  let attrHeight = 0;
  if (attrFrame) {
    attrFrame.y = header ? header.y + header.height : 72;
    const rows = attrFrame.children.filter((c) => c.name.startsWith("Kolom /") || c.type === "FRAME") as FrameNode[];
    const rowH = 36;
    const startY = 10;

    for (let i = 0; i < rows.length; i++) {
      const row = rows[i];
      row.resize(table.width, rowH);
      row.x = 0;
      row.y = startY + i * rowH;

      const texts = row.children.filter((c) => c.type === "TEXT") as TextNode[];
      if (texts.length >= 1) {
        const badge = texts[0];
        const isPk = badge.characters.includes("PK");
        const isFk = badge.characters.includes("FK");
        const isUk = badge.characters.includes("UK");
        const color = isPk
          ? { r: 0.02, g: 0.2, b: 0.6 }
          : isFk
          ? { r: 0.45, g: 0.18, b: 0.65 }
          : isUk
          ? { r: 0.0, g: 0.45, b: 0.35 }
          : { r: 0.48, g: 0.52, b: 0.58 };
        await styleTextNode(badge, 12.5, "Bold", color);
        badge.x = 16;
        badge.y = 9;
      }
      if (texts.length >= 2) {
        const colName = texts[1];
        await styleTextNode(colName, 14.5, "SemiBold", { r: 0.08, g: 0.12, b: 0.2 });
        colName.x = 90;
        colName.y = 8;
      }
      if (texts.length >= 3) {
        const colType = texts[2];
        await styleTextNode(colType, 13, "Medium", { r: 0.35, g: 0.4, b: 0.48 });
        colType.x = 405;
        colType.y = 8.5;
      }
    }

    attrHeight = startY + rows.length * rowH + 10;
    attrFrame.resize(table.width, attrHeight);
  }

  // 3. Extra constraint frames (like 'Primary dan unique keys')
  let currentY = (header ? header.y + header.height : 72) + attrHeight;
  for (const child of table.children) {
    if (child.name !== "Nama tabel" && child.name !== "Atribut dan kunci" && child.type === "FRAME") {
      child.y = currentY;
      child.resize(table.width, child.height);
      const innerTexts = child.findAll((n) => n.type === "TEXT") as TextNode[];
      for (const t of innerTexts) {
        await styleTextNode(t, 12.5, "Medium", { r: 0.3, g: 0.35, b: 0.42 });
      }
      currentY += child.height;
    }
  }

  const finalTableHeight = currentY + 2;
  table.resize(table.width, finalTableHeight);
}

async function formatErdRef(ref: FrameNode): Promise<void> {
  const header = ref.findOne((n) => n.name === "Nama tabel" && n.type === "FRAME") as FrameNode | null;
  if (header) {
    header.resize(ref.width, 68);
    const textChildren = header.children.filter((c) => c.type === "TEXT") as TextNode[];
    if (textChildren.length >= 1) {
      await styleTextNode(textChildren[0], 18, "Bold", { r: 0.05, g: 0.1, b: 0.22 });
      textChildren[0].x = 16;
      textChildren[0].y = 10;
    }
    if (textChildren.length >= 2) {
      await styleTextNode(textChildren[1], 13, "Regular", { r: 0.4, g: 0.45, b: 0.52 });
      textChildren[1].x = 16;
      textChildren[1].y = 38;
    }
  }

  const descText = ref.children.find((c) => c.type === "TEXT" && (c.name.includes("modul") || c.name.includes("Lihat"))) as TextNode | undefined;
  if (descText) {
    await styleTextNode(descText, 12.5, "Medium", { r: 0.35, g: 0.4, b: 0.48 });
    descText.x = 16;
    descText.y = 74;
  }

  ref.resize(ref.width, 102);
}

async function refactorErdAkun(erd01Frame: FrameNode): Promise<void> {
  // 1. Hide legacy vector connectors & cardinality frames so they don't break the compact grid
  const relasiNode = erd01Frame.findOne((n) => n.name.startsWith("Relasi /") || n.id === "1478:2424");
  if (relasiNode) {
    relasiNode.visible = false;
  }
  const kardinalitasNode = erd01Frame.findOne((n) => n.name.startsWith("Kardinalitas /") || n.id === "1478:2768");
  if (kardinalitasNode) {
    kardinalitasNode.visible = false;
  }

  // 2. Find all 7 tables in 01
  const tRoles = erd01Frame.findOne((n) => n.name.includes("roles") && !n.name.includes("user_roles") && n.type === "FRAME") as FrameNode | null;
  const tUsers = erd01Frame.findOne((n) => n.name === "Tabel / users" && n.type === "FRAME") as FrameNode | null;
  const tUserRoles = erd01Frame.findOne((n) => n.name.includes("user_roles") && n.type === "FRAME") as FrameNode | null;
  const tAuthSessions = erd01Frame.findOne((n) => n.name.includes("auth_sessions") && n.type === "FRAME") as FrameNode | null;
  const tTokens = erd01Frame.findOne((n) => n.name.includes("verification_tokens") && n.type === "FRAME") as FrameNode | null;
  const tRequests = erd01Frame.findOne((n) => n.name.includes("account_requests") && n.type === "FRAME") as FrameNode | null;
  const tClassRep = erd01Frame.findOne((n) => n.name.includes("class_representatives") && n.type === "FRAME") as FrameNode | null;

  // 3. Find 3 reference frames
  const refStudents = erd01Frame.findOne((n) => n.name.includes("REF / students") && n.type === "FRAME") as FrameNode | null;
  const refClassGroups = erd01Frame.findOne((n) => n.name.includes("REF / class_groups") && n.type === "FRAME") as FrameNode | null;
  const refTerms = erd01Frame.findOne((n) => n.name.includes("REF / academic_terms") && n.type === "FRAME") as FrameNode | null;

  // Format all tables
  const allTables = [tRoles, tUsers, tUserRoles, tAuthSessions, tTokens, tRequests, tClassRep];
  for (const t of allTables) {
    if (t) {
      await formatErdTable(t);
    }
  }

  // Format reference frames
  const allRefs = [refStudents, refClassGroups, refTerms];
  for (const r of allRefs) {
    if (r) {
      await formatErdRef(r);
    }
  }

  // 4. Place in compact 3-column grid
  const startY = 150;
  const vGap = 24;

  // Column 1 (x = 48): roles -> users -> REF students
  let col1Y = startY;
  if (tRoles) {
    tRoles.x = 48;
    tRoles.y = col1Y;
    col1Y += tRoles.height + vGap;
  }
  if (tUsers) {
    tUsers.x = 48;
    tUsers.y = col1Y;
    col1Y += tUsers.height + vGap;
  }
  if (refStudents) {
    refStudents.x = 48;
    refStudents.y = col1Y;
    col1Y += refStudents.height + vGap;
  }

  // Column 2 (x = 708): user_roles -> auth_sessions -> verification_tokens
  let col2Y = startY;
  if (tUserRoles) {
    tUserRoles.x = 708;
    tUserRoles.y = col2Y;
    col2Y += tUserRoles.height + vGap;
  }
  if (tAuthSessions) {
    tAuthSessions.x = 708;
    tAuthSessions.y = col2Y;
    col2Y += tAuthSessions.height + vGap;
  }
  if (tTokens) {
    tTokens.x = 708;
    tTokens.y = col2Y;
    col2Y += tTokens.height + vGap;
  }

  // Column 3 (x = 1368): account_requests -> class_representatives -> REF class_groups -> REF academic_terms
  let col3Y = startY;
  if (tRequests) {
    tRequests.x = 1368;
    tRequests.y = col3Y;
    col3Y += tRequests.height + vGap;
  }
  if (tClassRep) {
    tClassRep.x = 1368;
    tClassRep.y = col3Y;
    col3Y += tClassRep.height + vGap;
  }
  if (refClassGroups) {
    refClassGroups.x = 1368;
    refClassGroups.y = col3Y;
    col3Y += refClassGroups.height + 16;
  }
  if (refTerms) {
    refTerms.x = 1368;
    refTerms.y = col3Y;
    col3Y += refTerms.height + vGap;
  }

  // 5. Footer Text repositioning
  const maxBottom = Math.max(col1Y, col2Y, col3Y);
  const footerText = erd01Frame.findOne((n) => n.id === "1478:2829" || (n.type === "TEXT" && n.characters.includes("PK primary key"))) as TextNode | null;
  if (footerText) {
    await styleTextNode(footerText, 12.5, "Medium", { r: 0.45, g: 0.48, b: 0.55 });
    footerText.x = 48;
    footerText.y = maxBottom + 16;
    footerText.resize(1944, footerText.height);
  }

  // 6. Header Text repositioning & styling
  const headerBadge = erd01Frame.findOne((n) => n.id === "1478:2421" || (n.type === "TEXT" && n.characters.includes("SICMA / ERD"))) as TextNode | null;
  if (headerBadge) {
    await styleTextNode(headerBadge, 13, "Bold", { r: 0.02, g: 0.2, b: 0.6 });
    headerBadge.x = 48;
    headerBadge.y = 28;
  }

  const headerTitle = erd01Frame.findOne((n) => n.id === "1478:2422" || (n.type === "TEXT" && n.characters.includes("Akun, persetujuan"))) as TextNode | null;
  if (headerTitle) {
    await styleTextNode(headerTitle, 26, "Bold", { r: 0.08, g: 0.12, b: 0.2 });
    headerTitle.x = 48;
    headerTitle.y = 54;
  }

  const headerDesc = erd01Frame.findOne((n) => n.id === "1478:2423" || (n.type === "TEXT" && n.characters.includes("7 tabel utama"))) as TextNode | null;
  if (headerDesc) {
    await styleTextNode(headerDesc, 13.5, "Regular", { r: 0.38, g: 0.42, b: 0.5 });
    headerDesc.x = 48;
    headerDesc.y = 96;
    headerDesc.resize(1944, headerDesc.height);
  }

  // 7. Resize Frame to compact dimensions (saving 43% space)
  const finalFrameHeight = Math.max(1240, (footerText ? footerText.y + footerText.height + 36 : maxBottom + 48));
  erd01Frame.resize(2040, finalFrameHeight);
}

async function refactorAllTablesOnErdPage(page: PageNode): Promise<number> {
  const allTables = page.findAll((n) => n.type === "FRAME" && n.name.startsWith("Tabel /")) as FrameNode[];
  for (const t of allTables) {
    await formatErdTable(t);
  }
  return allTables.length;
}

async function applyErdOptimization(): Promise<number> {
  try {
    // 1. Load fonts
    try {
      await figma.loadFontAsync({ family: "Inter", style: "Regular" });
      await figma.loadFontAsync({ family: "Inter", style: "Medium" });
      await figma.loadFontAsync({ family: "Inter", style: "Semi Bold" });
      await figma.loadFontAsync({ family: "Inter", style: "Bold" });
    } catch {
      // fallback
    }

    // 2. Find ERD Page
    const erdPage = figma.root.children.find((p) => p.id === "249:1841" || p.name.includes("ERD"));
    if (!erdPage) {
      figma.notify("❌ Halaman ERD (05 — ERD Lengkap SICMA) tidak ditemukan.", { error: true });
      return 0;
    }

    await erdPage.loadAsync();
    if (figma.currentPage.id !== erdPage.id) {
      await figma.setCurrentPageAsync(erdPage);
    }

    // 3. Compact Frame 01 (Akun, Persetujuan dan Keti)
    const erd01 = erdPage.findOne((n) => n.id === "1478:2420" || (n.type === "FRAME" && n.name.includes("01 / Akun"))) as FrameNode | null;
    if (erd01) {
      await refactorErdAkun(erd01);
    }

    // 4. Format all other tables on the ERD page
    const totalTables = await refactorAllTablesOnErdPage(erdPage);

    figma.notify(`✅ Sukses! ${totalTables} tabel ERD diperbesar fontnya & layout 01 dirapikan kompak.`);
    figma.ui.postMessage({ type: "done-erd", count: totalTables });
    return totalTables;
  } catch (err: unknown) {
    const errorMsg = err instanceof Error ? err.message : String(err);
    figma.notify("❌ Gagal merapikan ERD: " + errorMsg, { error: true });
    figma.ui.postMessage({ type: "error", message: errorMsg });
    return 0;
  }
}


async function fixFilterSayaLabels(): Promise<number> {
  let count = 0;
  try {
    try {
      await figma.loadFontAsync({ family: "Plus Jakarta Sans", style: "Regular" });
    } catch {}
    try {
      await figma.loadFontAsync({ family: "Inter", style: "Regular" });
    } catch {}

    // 1. Direct target node 150:2061 (Filter / Saya on Lo-Fi page)
    const target = figma.getNodeById("150:2061");
    if (target && "findAll" in target) {
      const texts = (target as any).findAll((n: any) => n.type === "TEXT") as TextNode[];
      for (const t of texts) {
        if (t.fontName !== figma.mixed) {
          try {
            await figma.loadFontAsync(t.fontName);
          } catch {}
        }
        t.characters = "Saya";
        count++;
      }
    }

    // 2. Scan all pages for any node named "Filter / Saya" or text starting with "Saya " or "Saya 1" or "Saya 2"
    for (const page of figma.root.children) {
      try {
        await page.loadAsync();
        const matches = page.findAll((n) => 
          n.name === "Filter / Saya" || 
          (n.type === "TEXT" && (n.characters === "Saya 2" || n.characters === "Saya 1" || n.characters === "Saya 0" || n.characters.trim() === "Saya 2"))
        );
        for (const m of matches) {
          if (m.type === "TEXT") {
            const tn = m as TextNode;
            if (tn.fontName !== figma.mixed) {
              try {
                await figma.loadFontAsync(tn.fontName);
              } catch {}
            }
            tn.characters = "Saya";
            count++;
          } else if ("findAll" in m) {
            const innerTexts = (m as any).findAll((n: any) => n.type === "TEXT") as TextNode[];
            for (const tn of innerTexts) {
              if (tn.characters.includes("Saya 2") || tn.characters.includes("Saya 1") || tn.characters.trim() === "Saya 2" || tn.characters.trim() === "Saya 1") {
                if (tn.fontName !== figma.mixed) {
                  try {
                    await figma.loadFontAsync(tn.fontName);
                  } catch {}
                }
                tn.characters = "Saya";
                count++;
              }
            }
          }
        }
      } catch {}
    }

    figma.notify(`✅ Sukses! ${count} label 'Saya' diperbarui (tanpa angka).`);
    figma.ui.postMessage({ type: "done-filter", count });
    return count;
  } catch (err: unknown) {
    const errorMsg = err instanceof Error ? err.message : String(err);
    figma.notify("❌ Gagal update filter: " + errorMsg, { error: true });
    figma.ui.postMessage({ type: "error", message: errorMsg });
    return 0;
  }
}


figma.ui.onmessage = async (msg: { type: string }) => {
  if (msg.type === "run-filter") {
    await fixFilterSayaLabels();
  } else if (msg.type === "run-erd") {
    await applyErdOptimization();
  } else if (msg.type === "run-screens") {
    await applyFixes();
  } else if (msg.type === "run-all" || msg.type === "run") {
    await fixFilterSayaLabels();
    await applyErdOptimization();
    await applyFixes();
  }
};

// Auto-run filter label fix and ERD optimization when plugin launches
fixFilterSayaLabels();
applyErdOptimization();
