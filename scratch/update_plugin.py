import json
import os

source_file = r"C:\Users\LENOVO\.gemini\antigravity\brain\882e402b-b0de-4ec0-9aac-10d9972f028c\.system_generated\steps\1417\resource_0.txt"
with open(source_file, "r", encoding="utf-8") as f:
    code_content = f.read()

# Add the new function before figma.ui.onmessage
fix_function = """
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
"""

replacement_handler = """
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
"""

target_split = "figma.ui.onmessage = async (msg: { type: string }) => {"
parts = code_content.split(target_split)
if len(parts) == 2:
    new_code = parts[0] + fix_function + "\n" + replacement_handler
    with open("scratch/updated_code.ts", "w", encoding="utf-8") as f:
        f.write(new_code)
    print("Successfully created scratch/updated_code.ts, length:", len(new_code))
else:
    print("Could not find split target!")
