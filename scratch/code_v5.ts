
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
          return c === "Saya 1" || c === "Saya 2" || c === "Saya 0" || c === "Saya 3" || /^Saya\s+\d+$/i.test(c);
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
            if (raw === "Saya 1" || raw === "Saya 2" || raw === "Saya 0" || /^Saya\s+\d+$/i.test(raw)) {
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
