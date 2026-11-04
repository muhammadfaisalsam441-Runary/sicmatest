import os

source_file = r"C:\Users\LENOVO\.gemini\antigravity\brain\882e402b-b0de-4ec0-9aac-10d9972f028c\.system_generated\steps\1417\resource_0.txt"
with open(source_file, "r", encoding="utf-8") as f:
    lines = f.readlines()

erd_section = "".join(lines[1020:1358])

fix_section = """
figma.showUI(__html__, { width: 380, height: 340, themeColors: true });

async function fixFilterSayaLabels(): Promise<{ count: number; details: string[] }> {
  let count = 0;
  const details: string[] = [];

  const fontFamilies = ["Plus Jakarta Sans", "Inter"];
  const fontStyles = ["Regular", "Medium", "SemiBold", "Semi Bold", "Bold"];
  for (const family of fontFamilies) {
    for (const style of fontStyles) {
      try {
        await figma.loadFontAsync({ family, style });
      } catch {}
    }
  }

  async function updateText(textNode: TextNode, contextDesc: string): Promise<boolean> {
    try {
      if (textNode.fontName !== figma.mixed) {
        await figma.loadFontAsync(textNode.fontName);
      }
      textNode.characters = "Saya";
      count++;
      details.push(contextDesc);
      return true;
    } catch (err) {
      console.log("Error updating text node:", err);
      return false;
    }
  }

  // 1. Direct Target Node 150:2061 (Instance 'Filter / Saya')
  const specificNode = figma.getNodeById("150:2061");
  if (specificNode) {
    if (specificNode.type === "TEXT") {
      await updateText(specificNode as TextNode, "Node 150:2061 (Filter / Saya)");
    } else if ("findAll" in specificNode) {
      const texts = (specificNode as FrameNode | InstanceNode).findAll((n) => n.type === "TEXT") as TextNode[];
      for (const t of texts) {
        if (t.characters !== "Saya") {
          await updateText(t, `Node 150:2061 > ${t.name} ('${t.characters}' -> 'Saya')`);
        }
      }
    }
  }

  // 2. Scan across all pages in document for Filter / Saya or numbered variants
  for (const page of figma.root.children) {
    try {
      await page.loadAsync();

      const filterNodes = page.findAll((n) => {
        if (n.name === "Filter / Saya") return true;
        if (n.type === "TEXT") {
          const c = n.characters.trim();
          return c === "Saya 1" || c === "Saya 2" || c === "Saya 0" || c === "Saya 3" || /^Saya\\s+\\d+$/i.test(c);
        }
        return false;
      });

      for (const node of filterNodes) {
        if (node.type === "TEXT") {
          const tn = node as TextNode;
          if (tn.characters.trim() !== "Saya") {
            await updateText(tn, `${page.name} > Text ${tn.name} ('${tn.characters}' -> 'Saya')`);
          }
        } else if ("findAll" in node) {
          const textChildren = (node as FrameNode | InstanceNode).findAll((n) => n.type === "TEXT") as TextNode[];
          for (const tc of textChildren) {
            const raw = tc.characters.trim();
            if (raw === "Saya 1" || raw === "Saya 2" || raw === "Saya 0" || /^Saya\\s+\\d+$/i.test(raw)) {
              await updateText(tc, `${page.name} > ${node.name} > ${tc.name} ('${raw}' -> 'Saya')`);
            }
          }
        }
      }
    } catch (e) {
      console.log("Error loading page:", e);
    }
  }

  return { count, details };
}
"""

msg_handler = """
figma.ui.onmessage = async (msg: { type: string }) => {
  if (msg.type === "run-filter") {
    try {
      const res = await fixFilterSayaLabels();
      figma.notify(`✅ Sukses! ${res.count} label filter 'Saya' diperbaiki.`);
      figma.ui.postMessage({ type: "done-filter", count: res.count, details: res.details });
    } catch (err: unknown) {
      const m = err instanceof Error ? err.message : String(err);
      figma.notify("❌ Gagal: " + m, { error: true });
      figma.ui.postMessage({ type: "error", message: m });
    }
  } else if (msg.type === "run-erd") {
    await applyErdOptimization();
  } else if (msg.type === "run-all") {
    const res = await fixFilterSayaLabels();
    const erdCount = await applyErdOptimization();
    figma.notify(`✅ Sukses! Filter 'Saya' & ${erdCount} tabel ERD diperbarui.`);
    figma.ui.postMessage({ type: "done-all", filterCount: res.count, erdCount });
  }
};

// Auto-run filter repair immediately on launch
(async () => {
  try {
    const res = await fixFilterSayaLabels();
    figma.notify(`✅ Auto-run: ${res.count} label 'Saya' diperbaiki.`);
    figma.ui.postMessage({ type: "done-filter", count: res.count, details: res.details });
  } catch (err: unknown) {
    const m = err instanceof Error ? err.message : String(err);
    figma.ui.postMessage({ type: "error", message: m });
  }
})();
"""

full_code = fix_section + "\n" + erd_section + "\n" + msg_handler
with open("scratch/code_v5.ts", "w", encoding="utf-8") as f:
    f.write(full_code)

print("Full code length:", len(full_code), "lines:", len(full_code.splitlines()))
