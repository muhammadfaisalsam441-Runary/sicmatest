import * as THREE from './vendor/three.module.min.js';
import { GLTFLoader } from './vendor/GLTFLoader.js';
import { SICMA_LOGO_PARTS } from './sicma-logo-paths.js';

const SPLASH_SEEN_KEY = 'sicma.splash.seen';
const TOTAL_DURATION_MS = 4300;
const FADE_OUT_MS = 500;

let activeSession = null;
let sessionSeq = 0;
let gltfCachePromise = null;
let gltfCacheUrl = null;

function easeOutQuint(x) {
    const t = Math.max(0, Math.min(1, x));
    return 1 - Math.pow(1 - t, 5);
}

function easeInOutSine(x) {
    const t = Math.max(0, Math.min(1, x));
    return -(Math.cos(Math.PI * t) - 1) / 2;
}

function smoothstep(edge0, edge1, x) {
    const t = Math.max(0, Math.min(1, (x - edge0) / (edge1 - edge0)));
    return t * t * (3 - 2 * t);
}

function createSeededRandom(seed) {
    let s = seed >>> 0;
    return () => {
        s = (s * 1664525 + 1013904223) >>> 0;
        return s / 4294967296;
    };
}

function loadCachedLogoGltf(url) {
    if (!gltfCachePromise || gltfCacheUrl !== url) {
        gltfCacheUrl = url;
        const loader = new GLTFLoader();
        gltfCachePromise = loader.loadAsync(url).catch((err) => {
            gltfCachePromise = null;
            throw err;
        });
    }
    return gltfCachePromise;
}

function createShapeFromPoints(points) {
    const shape = new THREE.Shape();
    if (!points || points.length < 3) {
        return shape;
    }
    shape.moveTo(points[0][0], points[0][1]);
    for (let i = 1; i < points.length; i++) {
        shape.lineTo(points[i][0], points[i][1]);
    }
    shape.closePath();
    return shape;
}

function createStudioEnvironment(renderer) {
    const pmrem = new THREE.PMREMGenerator(renderer);
    pmrem.compileEquirectangularShader();

    const envScene = new THREE.Scene();
    envScene.background = new THREE.Color(0x041022);

    const panels = [
        { pos: [6, 3.2, 4], rot: [0, -Math.PI * 0.35, 0], size: [5, 7], color: 0x16e0ff, intensity: 3.4 },
        { pos: [-6, 2.4, 4], rot: [0, Math.PI * 0.35, 0], size: [5, 7], color: 0x2563eb, intensity: 2.9 },
        { pos: [0, 7, 2], rot: [Math.PI * 0.45, 0, 0], size: [9, 4], color: 0xe0f2fe, intensity: 2.4 },
        { pos: [0, -6, 3], rot: [-Math.PI * 0.45, 0, 0], size: [9, 4], color: 0x061a40, intensity: 1.1 },
    ];

    const createdGeos = [];
    const createdMats = [];

    for (const cfg of panels) {
        const geo = new THREE.PlaneGeometry(cfg.size[0], cfg.size[1]);
        const c = new THREE.Color(cfg.color).multiplyScalar(cfg.intensity);
        const mat = new THREE.MeshBasicMaterial({ color: c, side: THREE.DoubleSide });
        const mesh = new THREE.Mesh(geo, mat);
        mesh.position.set(cfg.pos[0], cfg.pos[1], cfg.pos[2]);
        mesh.rotation.set(cfg.rot[0], cfg.rot[1], cfg.rot[2]);
        envScene.add(mesh);
        createdGeos.push(geo);
        createdMats.push(mat);
    }

    const renderTarget = pmrem.fromScene(envScene, 0.04);
    for (const geo of createdGeos) geo.dispose();
    for (const mat of createdMats) mat.dispose();
    pmrem.dispose();

    return renderTarget;
}

function buildOverlayDom(root) {
    root.innerHTML = '';
    root.style.display = 'flex';
    root.style.opacity = '1';
    root.style.transition = `opacity ${FADE_OUT_MS}ms cubic-bezier(0.22, 1, 0.36, 1)`;
    root.className = 'pointer-events-auto fixed inset-0 z-[100] flex flex-col items-center justify-center overflow-hidden bg-[#041022] select-none';
    root.setAttribute('aria-hidden', 'false');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Animasi pembuka 3D SICMA');

    root.innerHTML = `
        <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(circle at 50% 45%, rgba(16, 78, 185, 0.30) 0%, rgba(4, 16, 34, 0.94) 62%, #020814 100%);"></div>
        <canvas data-splash-canvas class="absolute inset-0 block h-full w-full"></canvas>

        <div data-splash-copy class="pointer-events-none relative z-10 mt-[34vh] hidden flex-col items-center px-6 text-center transition-all duration-700" style="opacity: 0; transform: translateY(16px);">
            <h2 class="text-[clamp(2.25rem,6vw,3.5rem)] leading-none font-extrabold tracking-[0.14em] text-white">SICMA</h2>
            <p class="mt-2 text-sm font-medium tracking-[0.06em] text-sky-100/90 sm:text-base">Solusi Cerdas Reservasi Kelas</p>
        </div>

        <div class="pointer-events-none fixed inset-x-0 bottom-7 z-20 flex flex-col items-center gap-3 px-6">
            <div class="h-1 w-44 overflow-hidden rounded-full bg-white/10">
                <div data-splash-progress
                     class="h-full w-full origin-left rounded-full bg-gradient-to-r from-[#2D68C4] via-[#00F0FF] to-[#7CF7FF] shadow-[0_0_12px_rgba(0,240,255,0.8)]"
                     style="transform: scaleX(0); will-change: transform;"></div>
            </div>
        </div>

        <button type="button"
                data-splash-skip
                class="fixed top-5 right-5 z-20 flex cursor-pointer items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-4 py-2 text-xs font-semibold tracking-wide text-white/90 backdrop-blur-md transition hover:border-cyan-300/60 hover:bg-white/15 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400 sm:top-7 sm:right-7">
            <span>Lewati</span>
            <span aria-hidden="true" class="text-[10px] text-white/60">ESC</span>
        </button>
    `;

    return {
        canvas: root.querySelector('[data-splash-canvas]'),
        copyEl: root.querySelector('[data-splash-copy]'),
        progressEl: root.querySelector('[data-splash-progress]'),
        skipBtn: root.querySelector('[data-splash-skip]'),
    };
}

function markSplashSeen() {
    try {
        window.sessionStorage.setItem(SPLASH_SEEN_KEY, '1');
    } catch {
        // Abaikan jika sessionStorage dibatasi browser.
    }
}

function playReducedMotionSplash(root) {
    const ui = buildOverlayDom(root);
    if (ui.copyEl) {
        ui.copyEl.classList.remove('hidden');
        ui.copyEl.classList.add('flex');
        ui.copyEl.style.opacity = '1';
        ui.copyEl.style.transform = 'translateY(0px)';
    }
    if (ui.progressEl) {
        ui.progressEl.style.transform = 'scaleX(1)';
    }

    const fallbackSvg = document.createElement('div');
    fallbackSvg.className = 'pointer-events-none relative z-10 -mt-[40vh] flex items-center justify-center';
    fallbackSvg.innerHTML = `
        <svg viewBox="0 0 100 100" class="size-36 drop-shadow-[0_12px_36px_rgba(0,240,255,0.3)] sm:size-44" aria-hidden="true">
            <rect x="13" y="16" width="74" height="74" rx="16" fill="#071A42" />
            ${Object.values(SICMA_LOGO_PARTS).map((part) => `
                <path d="${part.svgPath}" fill="${part.role === 'cyan' ? '#00F0FF' : '#2D68C4'}" />
            `).join('')}
        </svg>
    `;
    root.insertBefore(fallbackSvg, ui.copyEl);

    let finished = false;
    const cleanup = () => {
        if (finished) return;
        finished = true;
        window.removeEventListener('keydown', onKey);
        root.style.opacity = '0';
        setTimeout(() => {
            root.innerHTML = '';
            root.style.display = 'none';
            root.className = 'pointer-events-none fixed inset-0 z-[100]';
            root.setAttribute('aria-hidden', 'true');
            root.removeAttribute('role');
            root.removeAttribute('aria-modal');
            if (activeSession?.cleanup === cleanup) activeSession = null;
        }, FADE_OUT_MS);
    };

    const onKey = (e) => {
        if (e.key === 'Escape') cleanup();
    };
    ui.skipBtn?.addEventListener('click', cleanup);
    window.addEventListener('keydown', onKey);
    const timer = setTimeout(cleanup, 1100);

    return {
        cleanup: () => {
            clearTimeout(timer);
            cleanup();
        },
    };
}

function classifyMeshRole(name) {
    if (name === 'Checkmark_Cyan') return 'cyan';
    if (name.startsWith('SICMA_')) return 'silver';
    if (name.startsWith('Text_Subtitle')) return 'subtitle';
    if (name.includes('Backplate')) return 'backplate';
    return 'cobalt';
}

function phaseForMeshName(name) {
    if (name.startsWith('Text_Subtitle')) return 0.02;
    if (name === 'SICMA_S') return 0.06;
    if (name === 'SICMA_I') return 0.09;
    if (name === 'SICMA_C') return 0.12;
    if (name === 'SICMA_M') return 0.15;
    if (name === 'SICMA_A') return 0.18;
    if (name.includes('Backplate')) return 0.16;
    if (name === 'S_Calendar_Lower') return 0.20;
    if (name === 'S_Calendar_Upper') return 0.26;
    if (name === 'Binder_Tab_Left') return 0.31;
    if (name === 'Binder_Tab_Right') return 0.34;
    if (name === 'Checkmark_Cyan') return 0.36;
    return 0.30;
}

function buildRoleMaterial(role, clipPlane, track) {
    if (role === 'backplate') {
        return track(new THREE.MeshStandardMaterial({
            color: 0x05163a,
            metalness: 0.12,
            roughness: 0.55,
            envMapIntensity: 0.25,
            clippingPlanes: [clipPlane],
            clipShadows: true,
        }));
    }
    if (role === 'cyan') {
        return track(new THREE.MeshStandardMaterial({
            color: 0x00f0ff,
            emissive: 0x00e5ff,
            emissiveIntensity: 0.95,
            metalness: 0.10,
            roughness: 0.12,
            envMapIntensity: 0.85,
            side: THREE.DoubleSide,
            clippingPlanes: [clipPlane],
            clipShadows: true,
        }));
    }
    if (role === 'silver') {
        return track(new THREE.MeshStandardMaterial({
            color: 0xf1f5f9,
            emissive: 0xdbeafe,
            emissiveIntensity: 0.22,
            metalness: 0.20,
            roughness: 0.18,
            envMapIntensity: 0.90,
            clippingPlanes: [clipPlane],
            clipShadows: true,
        }));
    }
    if (role === 'subtitle') {
        return track(new THREE.MeshStandardMaterial({
            color: 0xe2effc,
            emissive: 0xdbeafe,
            emissiveIntensity: 0.40,
            metalness: 0.10,
            roughness: 0.20,
            envMapIntensity: 0.85,
            clippingPlanes: [clipPlane],
            clipShadows: true,
        }));
    }
    return track(new THREE.MeshStandardMaterial({
        color: 0x2563eb,
        emissive: 0x0a2e78,
        emissiveIntensity: 0.22,
        metalness: 0.34,
        roughness: 0.20,
        envMapIntensity: 0.90,
        clippingPlanes: [clipPlane],
        clipShadows: true,
    }));
}

function buildFallbackLogoParts(clipPlane, track) {
    const group = new THREE.Group();
    const parts = [];
    const cobaltMat = buildRoleMaterial('cobalt', clipPlane, track);
    const cyanMat = buildRoleMaterial('cyan', clipPlane, track);

    for (const [id, part] of Object.entries(SICMA_LOGO_PARTS)) {
        const shape = createShapeFromPoints(part.points);
        const isCyan = part.role === 'cyan';
        const geo = track(new THREE.ExtrudeGeometry(shape, {
            depth: 0.18,
            bevelEnabled: true,
            bevelThickness: 0.030,
            bevelSize: 0.020,
            bevelSegments: 3,
        }));
        geo.translate(0, 0, isCyan ? -0.02 : -0.08);
        const mat = isCyan ? cyanMat : cobaltMat;
        const mesh = new THREE.Mesh(geo, mat);
        group.add(mesh);
        parts.push({
            name: id,
            role: isCyan ? 'cyan' : 'cobalt',
            mesh,
            material: mat,
            basePos: mesh.position.clone(),
            baseScale: mesh.scale.clone(),
            baseRot: mesh.rotation.clone(),
            baseEmissive: mat.emissiveIntensity,
            phase: isCyan ? 0.25 : 0.12,
        });
    }

    return { group, parts, hasEmbeddedText: false };
}

function buildModelStateFromGltf(gltf, clipPlane, track) {
    const model = gltf.scene.clone(true);
    const box = new THREE.Box3().setFromObject(model);
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const uniformScale = 2.38 / Math.max(size.y, 0.001);

    const wrapper = new THREE.Group();
    model.position.set(-center.x, -center.y, -center.z);
    wrapper.add(model);
    wrapper.scale.setScalar(uniformScale);
    wrapper.position.set(0, 0.02, 0);
    wrapper.updateMatrixWorld(true);

    const sharedMaterials = {
        backplate: buildRoleMaterial('backplate', clipPlane, track),
        cobalt: buildRoleMaterial('cobalt', clipPlane, track),
        cyan: buildRoleMaterial('cyan', clipPlane, track),
        silver: buildRoleMaterial('silver', clipPlane, track),
        subtitle: buildRoleMaterial('subtitle', clipPlane, track),
    };

    const parts = [];
    const vertexPools = { cobalt: [], cyan: [], silver: [] };

    wrapper.traverse((child) => {
        if (!child.isMesh) return;
        const role = classifyMeshRole(child.name);
        const mat = sharedMaterials[role] || sharedMaterials.cobalt;
        child.material = mat;
        child.visible = true;

        parts.push({
            name: child.name,
            role,
            mesh: child,
            material: mat,
            basePos: child.position.clone(),
            baseScale: child.scale.clone(),
            baseRot: child.rotation.clone(),
            baseEmissive: mat.emissiveIntensity,
            phase: phaseForMeshName(child.name),
        });

        const posAttr = child.geometry?.attributes?.position;
        if (posAttr && role !== 'backplate') {
            const poolKey = role === 'subtitle' ? 'silver' : role;
            const targetPool = vertexPools[poolKey] || vertexPools.cobalt;
            const step = Math.max(1, Math.floor(posAttr.count / 50));
            const vTmp = new THREE.Vector3();
            for (let vi = 0; vi < posAttr.count; vi += step) {
                vTmp.fromBufferAttribute(posAttr, vi).applyMatrix4(child.matrixWorld);
                targetPool.push(vTmp.clone());
            }
        }
    });

    return {
        group: wrapper,
        parts,
        hasEmbeddedText: true,
        sharedMaterials,
        vertexPools,
    };
}

export async function playSicmaSplash(options = {}) {
    const root = document.getElementById('sicma-splash-root');
    if (!root) {
        return false;
    }

    const token = ++sessionSeq;

    if (activeSession) {
        activeSession.cleanup(true);
        activeSession = null;
    }

    markSplashSeen();

    const prefersReduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced && !options.forceFullMotion) {
        activeSession = playReducedMotionSplash(root);
        return true;
    }

    const ui = buildOverlayDom(root);
    const canvas = ui.canvas;
    if (!canvas) {
        return false;
    }

    let renderer;
    try {
        renderer = new THREE.WebGLRenderer({
            canvas,
            antialias: true,
            alpha: true,
            powerPreference: 'high-performance',
        });
    } catch {
        activeSession = playReducedMotionSplash(root);
        return true;
    }

    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.35));
    renderer.setSize(window.innerWidth, window.innerHeight, false);
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.localClippingEnabled = true;

    const scene = new THREE.Scene();
    const envTarget = createStudioEnvironment(renderer);
    scene.environment = envTarget.texture;

    const camera = new THREE.PerspectiveCamera(38, window.innerWidth / Math.max(1, window.innerHeight), 0.1, 40);
    camera.position.set(1.0, -0.35, 6.4);

    // 4 cahaya ringkas untuk performa fragment shader optimal + pencahayaan muka depan merata
    const ambientLight = new THREE.AmbientLight(0x1e3f78, 1.05);
    scene.add(ambientLight);

    const keyLight = new THREE.DirectionalLight(0xeef6ff, 1.65);
    keyLight.position.set(-3.2, 4.4, 5.4);
    scene.add(keyLight);

    const frontFill = new THREE.DirectionalLight(0x9ec5ff, 0.55);
    frontFill.position.set(0.0, 0.3, 6.0);
    scene.add(frontFill);

    const rimCyan = new THREE.DirectionalLight(0x00f0ff, 2.25);
    rimCyan.position.set(4.2, 2.0, 1.8);
    scene.add(rimCyan);

    // Clipping plane vertikal untuk efek air naik membentuk logo Blender solid tanpa recompile shader
    const clipPlane = new THREE.Plane(new THREE.Vector3(0, -1, 0), -1.60);

    const logoRig = new THREE.Group();
    scene.add(logoRig);

    const fxGroup = new THREE.Group();
    logoRig.add(fxGroup);

    const disposables = [];
    const track = (obj) => {
        disposables.push(obj);
        return obj;
    };

    let rafId = null;
    let cleanedUp = false;
    let closing = false;

    const finishAndDispose = (immediate = false) => {
        if (cleanedUp) return;
        if (closing && !immediate) return;
        closing = true;

        if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('resize', onResize);
        window.removeEventListener('keydown', onKeyDown);

        const tearDown = () => {
            if (cleanedUp) return;
            cleanedUp = true;
            envTarget.dispose();
            for (const item of disposables) {
                item.dispose?.();
            }
            renderer.dispose();
            root.innerHTML = '';
            root.style.display = 'none';
            root.className = 'pointer-events-none fixed inset-0 z-[100]';
            root.setAttribute('aria-hidden', 'true');
            root.removeAttribute('role');
            root.removeAttribute('aria-modal');
            if (activeSession?.cleanup === finishAndDispose) {
                activeSession = null;
            }
        };

        if (immediate) {
            tearDown();
            return;
        }

        root.style.opacity = '0';
        setTimeout(tearDown, FADE_OUT_MS);
    };

    const onKeyDown = (e) => {
        if (e.key === 'Escape') {
            finishAndDispose(false);
        }
    };

    let pointerTargetX = 0;
    let pointerTargetY = 0;
    let pointerCurrentX = 0;
    let pointerCurrentY = 0;

    const onPointerMove = (e) => {
        const nx = (e.clientX / Math.max(1, window.innerWidth)) * 2 - 1;
        const ny = (e.clientY / Math.max(1, window.innerHeight)) * 2 - 1;
        pointerTargetX = Math.max(-1, Math.min(1, nx));
        pointerTargetY = Math.max(-1, Math.min(1, ny));
    };

    const onResize = () => {
        if (!renderer) return;
        const w = window.innerWidth;
        const h = Math.max(1, window.innerHeight);
        camera.aspect = w / h;
        const visibleHeightAt54 = 2 * 5.4 * Math.tan(THREE.MathUtils.degToRad(38 * 0.5));
        const visibleWidthAt54 = visibleHeightAt54 * camera.aspect;
        const requiredWidth = 2.55;
        camera.userZoomOffset = visibleWidthAt54 < requiredWidth
            ? ((requiredWidth / Math.max(0.4, visibleWidthAt54)) - 1) * 5.4
            : 0.0;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h, false);
    };
    onResize();

    ui.skipBtn?.addEventListener('click', () => finishAndDispose(false));
    window.addEventListener('pointermove', onPointerMove, { passive: true });
    window.addEventListener('resize', onResize);
    window.addEventListener('keydown', onKeyDown);
    activeSession = { cleanup: finishAndDispose };

    // Muat GLB (di-cache di modul) SEBELUM Frame 1 dimulai agar tidak stutter di tengah animasi
    const modelUrl = root.dataset.modelUrl || '/models/sicma-logo.glb';
    let loadedModelState = null;

    try {
        const gltf = await loadCachedLogoGltf(modelUrl);
        if (cleanedUp || closing || token !== sessionSeq) {
            return false;
        }
        loadedModelState = buildModelStateFromGltf(gltf, clipPlane, track);
    } catch {
        if (cleanedUp || closing || token !== sessionSeq) {
            return false;
        }
        loadedModelState = buildFallbackLogoParts(clipPlane, track);
    }

    logoRig.add(loadedModelState.group);

    // Kumpulkan target posisi permukaan asli dari model yang sudah siap
    const contourTargets = loadedModelState.vertexPools || { cobalt: [], cyan: [], silver: [] };
    if (!contourTargets.cobalt?.length) {
        contourTargets.cobalt = [new THREE.Vector3(0, 0.3, 0)];
    }
    if (!contourTargets.cyan?.length) {
        contourTargets.cyan = [new THREE.Vector3(0.1, 0.3, 0.05)];
    }
    if (!contourTargets.silver?.length) {
        contourTargets.silver = [new THREE.Vector3(0, -0.75, 0.02)];
    }

    const rng = createSeededRandom(20261117);

    // 10 pita aliran air 3D single-pass MeshStandardMaterial (tanpa multi-pass transmission)
    const ribbonMaterialCobalt = track(new THREE.MeshStandardMaterial({
        color: 0x3b82f6,
        emissive: 0x0ea5e9,
        emissiveIntensity: 0.75,
        roughness: 0.08,
        metalness: 0.15,
        envMapIntensity: 1.4,
    }));
    const ribbonMaterialCyan = track(new THREE.MeshStandardMaterial({
        color: 0x00f0ff,
        emissive: 0x00e5ff,
        emissiveIntensity: 1.15,
        roughness: 0.06,
        metalness: 0.15,
        envMapIntensity: 1.4,
    }));
    const ribbonMaterialSilver = track(new THREE.MeshStandardMaterial({
        color: 0xe2e8f0,
        emissive: 0x7dd3fc,
        emissiveIntensity: 0.45,
        roughness: 0.08,
        metalness: 0.22,
        envMapIntensity: 1.35,
    }));

    const ribbons = [];
    const RIBBON_COUNT = 10;
    for (let i = 0; i < RIBBON_COUNT; i++) {
        const role = i >= 8 ? 'cyan' : (i >= 5 ? 'silver' : 'cobalt');
        const pool = contourTargets[role];
        const endPt = (pool[Math.floor(rng() * pool.length)] || new THREE.Vector3()).clone();

        const theta = (i / RIBBON_COUNT) * Math.PI * 2 + rng() * 0.42;
        const outerR = 2.7 + rng() * 1.4;
        const startPt = new THREE.Vector3(
            Math.cos(theta) * outerR,
            Math.sin(theta) * outerR * 0.85 + (rng() - 0.5) * 0.7,
            (rng() - 0.5) * 2.0,
        );
        const midAngle = theta + (rng() > 0.5 ? 1.15 : -1.15);
        const midPt1 = new THREE.Vector3(
            Math.cos(midAngle) * (outerR * 0.62),
            Math.sin(midAngle) * (outerR * 0.56),
            (rng() - 0.5) * 1.2,
        );
        const midPt2 = new THREE.Vector3(
            endPt.x * 1.28 + Math.cos(midAngle + 0.8) * 0.42,
            endPt.y * 1.20 + Math.sin(midAngle + 0.8) * 0.42,
            0.30 + rng() * 0.40,
        );

        const curve = new THREE.CatmullRomCurve3([startPt, midPt1, midPt2, endPt]);
        const tubularSegments = 108;
        const radialSegments = 5;
        const radius = role === 'cyan' ? 0.022 + rng() * 0.01 : 0.030 + rng() * 0.015;
        const tubeGeo = track(new THREE.TubeGeometry(curve, tubularSegments, radius, radialSegments, false));
        const totalIndices = tubeGeo.index ? tubeGeo.index.count : tubeGeo.attributes.position.count;
        const mat = role === 'cyan'
            ? ribbonMaterialCyan
            : (role === 'silver' ? ribbonMaterialSilver : ribbonMaterialCobalt);

        const mesh = new THREE.Mesh(tubeGeo, mat);
        fxGroup.add(mesh);

        ribbons.push({
            mesh,
            geo: tubeGeo,
            totalIndices,
            radialSegments,
            delay: rng() * 0.20,
        });
    }

    // 96 tetesan air 3D ringan (56 cobalt, 20 cyan, 20 silver)
    const dropletGeo = track(new THREE.SphereGeometry(1, 10, 8));
    const dropletCobaltMat = track(new THREE.MeshStandardMaterial({
        color: 0x3b82f6,
        emissive: 0x0ea5e9,
        emissiveIntensity: 0.55,
        roughness: 0.08,
        metalness: 0.15,
        envMapIntensity: 1.4,
    }));
    const dropletCyanMat = track(new THREE.MeshStandardMaterial({
        color: 0x22f3ff,
        emissive: 0x00e0ff,
        emissiveIntensity: 0.95,
        roughness: 0.06,
        metalness: 0.12,
        envMapIntensity: 1.35,
    }));
    const dropletSilverMat = track(new THREE.MeshStandardMaterial({
        color: 0xe2e8f0,
        emissive: 0xbae6fd,
        emissiveIntensity: 0.50,
        roughness: 0.08,
        metalness: 0.25,
        envMapIntensity: 1.35,
    }));

    const cobaltDropsMesh = new THREE.InstancedMesh(dropletGeo, dropletCobaltMat, 56);
    const cyanDropsMesh = new THREE.InstancedMesh(dropletGeo, dropletCyanMat, 20);
    const silverDropsMesh = new THREE.InstancedMesh(dropletGeo, dropletSilverMat, 20);
    fxGroup.add(cobaltDropsMesh, cyanDropsMesh, silverDropsMesh);

    const buildDropParticles = (count, role) => {
        const pool = contourTargets[role];
        const list = [];
        for (let i = 0; i < count; i++) {
            const target = pool[Math.floor(rng() * pool.length)] || new THREE.Vector3();
            const angle = rng() * Math.PI * 2;
            const r = 1.7 + rng() * 2.5;
            const p0 = new THREE.Vector3(
                Math.cos(angle) * r * 1.15,
                Math.sin(angle) * r * 0.88 + (rng() - 0.5) * 0.9,
                (rng() - 0.5) * 2.2,
            );
            const swirlSide = rng() > 0.5 ? 1 : -1;
            const pMid = new THREE.Vector3(
                (p0.x + target.x) * 0.5 + (-p0.y * 0.36 * swirlSide),
                (p0.y + target.y) * 0.5 + (p0.x * 0.36 * swirlSide),
                p0.z * 0.35 + (rng() - 0.5) * 0.7,
            );
            const pEnd = new THREE.Vector3(
                target.x + (rng() - 0.5) * 0.05,
                target.y + (rng() - 0.5) * 0.05,
                target.z,
            );

            list.push({
                p0,
                pMid,
                pEnd,
                radius: (role === 'cyan' ? 0.028 : 0.034) + rng() * 0.048,
                phase: rng() * Math.PI * 2,
                speed: 1.1 + rng() * 0.9,
                startAt: rng() * 0.16,
                endAt: 0.48 + rng() * 0.24,
            });
        }
        return list;
    };

    const cobaltParticles = buildDropParticles(56, 'cobalt');
    const cyanParticles = buildDropParticles(20, 'cyan');
    const silverParticles = buildDropParticles(20, 'silver');

    // Cincin gelombang kejut cyan
    const shockwaveGeo = track(new THREE.RingGeometry(0.16, 0.25, 48));
    const shockwaveMat = track(new THREE.MeshBasicMaterial({
        color: 0x22f3ff,
        transparent: true,
        opacity: 0.0,
        side: THREE.DoubleSide,
    }));
    const shockwaveMesh = new THREE.Mesh(shockwaveGeo, shockwaveMat);
    shockwaveMesh.position.set(0.08, 0.22, 0.28);
    logoRig.add(shockwaveMesh);

    const dummyPos = new THREE.Vector3();
    const dummyNext = new THREE.Vector3();
    const dummyDir = new THREE.Vector3();
    const dummyQuat = new THREE.Quaternion();
    const dummyScale = new THREE.Vector3();
    const dummyMatrix = new THREE.Matrix4();
    const upAxis = new THREE.Vector3(0, 1, 0);

    const evalDropletPos = (p, tVal, elapsedSec, outVec) => {
        const clampedT = Math.max(0, Math.min(1, tVal));
        const chaosAmp = (1 - easeOutQuint(clampedT)) * 0.14;
        const inv = 1 - clampedT;

        const bx = inv * inv * p.p0.x + 2 * inv * clampedT * p.pMid.x + clampedT * clampedT * p.pEnd.x;
        const by = inv * inv * p.p0.y + 2 * inv * clampedT * p.pMid.y + clampedT * clampedT * p.pEnd.y;
        const bz = inv * inv * p.p0.z + 2 * inv * clampedT * p.pMid.z + clampedT * clampedT * p.pEnd.z;

        const wobbleX = Math.sin(elapsedSec * p.speed + p.phase) * chaosAmp;
        const wobbleY = Math.cos(elapsedSec * p.speed * 0.87 + p.phase * 1.3) * chaosAmp;
        const wobbleZ = Math.sin(elapsedSec * p.speed * 1.15 - p.phase) * chaosAmp * 0.7;

        outVec.set(bx + wobbleX, by + wobbleY, bz + wobbleZ);
    };

    const updateDroplets = (instancedMesh, particles, normT, elapsedSec) => {
        for (let i = 0; i < particles.length; i++) {
            const p = particles[i];
            const moveT = easeInOutSine(smoothstep(p.startAt, p.endAt, normT));

            evalDropletPos(p, moveT, elapsedSec, dummyPos);
            evalDropletPos(p, Math.min(1, moveT + 0.025), elapsedSec, dummyNext);

            dummyDir.subVectors(dummyNext, dummyPos);
            if (dummyDir.lengthSq() > 1e-6) {
                dummyDir.normalize();
                dummyQuat.setFromUnitVectors(upAxis, dummyDir);
            } else {
                dummyQuat.identity();
            }

            const lifeBell = Math.sin(moveT * Math.PI);
            const rScale = p.radius * Math.pow(Math.max(0, lifeBell), 0.45);
            const stretch = 1 + lifeBell * 1.25;

            if (rScale <= 0.0005) {
                dummyScale.set(0, 0, 0);
            } else {
                dummyScale.set(rScale * 0.85, rScale * stretch, rScale * 0.85);
            }

            dummyMatrix.compose(dummyPos, dummyQuat, dummyScale);
            instancedMesh.setMatrixAt(i, dummyMatrix);
        }
        instancedMesh.instanceMatrix.needsUpdate = true;
    };

    // Warm-up shader compilation + render 1 frame SEBELUM startTime dicatat agar Frame 1 bebas stutter
    for (const rib of ribbons) {
        rib.geo.setDrawRange(0, 0);
    }
    shockwaveMesh.scale.set(0.001, 0.001, 1);
    updateDroplets(cobaltDropsMesh, cobaltParticles, 0.0, 0.0);
    updateDroplets(cyanDropsMesh, cyanParticles, 0.0, 0.0);
    updateDroplets(silverDropsMesh, silverParticles, 0.0, 0.0);
    renderer.compile(scene, camera);
    renderer.render(scene, camera);

    if (cleanedUp || closing || token !== sessionSeq) {
        return false;
    }

    let startTime = 0;
    let lastNow = 0;

    const tick = (now) => {
        if (cleanedUp || closing || token !== sessionSeq) return;

        if (startTime === 0) {
            startTime = now;
        }
        const dtSec = lastNow > 0 ? Math.min(0.05, (now - lastNow) * 0.001) : 0.016;
        lastNow = now;

        const elapsedMs = now - startTime;
        const normT = Math.min(1, elapsedMs / TOTAL_DURATION_MS);
        const elapsedSec = elapsedMs * 0.001;

        if (ui.progressEl) {
            ui.progressEl.style.transform = `scaleX(${normT.toFixed(4)})`;
        }

        // 1. Update butiran & pita air halus (hilang setelah t norm > 0.78)
        const fxActive = normT <= 0.78;
        fxGroup.visible = fxActive;
        if (fxActive) {
            updateDroplets(cobaltDropsMesh, cobaltParticles, normT, elapsedSec);
            updateDroplets(cyanDropsMesh, cyanParticles, normT, elapsedSec);
            updateDroplets(silverDropsMesh, silverParticles, normT, elapsedSec);

            const headFrac = easeInOutSine(smoothstep(0.02, 0.58, normT));
            const tailFrac = easeInOutSine(smoothstep(0.26, 0.72, normT));
            for (const rib of ribbons) {
                const hd = rib.delay * 0.25;
                const td = rib.delay * 0.18;
                const h = smoothstep(0.0, 1.0, Math.max(0, (headFrac - hd) / Math.max(0.001, 1 - hd)));
                const t = smoothstep(0.0, 1.0, Math.max(0, (tailFrac - td) / Math.max(0.001, 1 - td)));
                const segCount = Math.floor(rib.totalIndices);
                const stride = rib.radialSegments * 6;
                const startIdx = Math.floor((t * segCount) / stride) * stride;
                const endIdx = Math.floor((h * segCount) / stride) * stride;
                const drawCount = Math.max(0, endIdx - startIdx);
                rib.geo.setDrawRange(startIdx, drawCount);
            }
        }

        // 2. Permukaan gelombang air miring lembut yang naik membentuk logo solid
        const fillProgress = easeInOutSine(smoothstep(0.18, 0.68, normT));
        const waveTilt = Math.sin(elapsedSec * 3.2) * 0.12 * (1 - fillProgress);
        clipPlane.normal.set(waveTilt, -1, 0).normalize();
        clipPlane.constant = -1.60 + fillProgress * 4.60 + Math.sin(elapsedSec * 4.5) * 0.025 * (1 - fillProgress);

        const solidifyFlash = Math.sin(smoothstep(0.50, 0.76, normT) * Math.PI);

        for (const part of loadedModelState.parts) {
            const localT = smoothstep(0.18 + part.phase * 0.38, 0.62 + part.phase * 0.38, normT);
            const damped = easeOutQuint(localT);
            const liquidSwell = 1.0 + Math.sin(localT * Math.PI) * 0.035 * (1 - damped);

            part.mesh.scale.set(
                part.baseScale.x * (0.88 + 0.12 * damped),
                part.baseScale.y * (0.88 + 0.12 * damped) * liquidSwell,
                part.baseScale.z * (0.78 + 0.22 * damped),
            );
            part.mesh.position.set(
                part.basePos.x,
                part.basePos.y - 0.14 * (1 - damped),
                part.basePos.z - 0.30 * (1 - damped),
            );
            part.mesh.rotation.set(
                part.baseRot.x + (1 - damped) * 0.10,
                part.baseRot.y - (1 - damped) * 0.08,
                part.baseRot.z,
            );
        }

        // Kilau emisi halus saat solid lock
        if (loadedModelState.sharedMaterials) {
            loadedModelState.sharedMaterials.cyan.emissiveIntensity = 0.95 + solidifyFlash * 1.10;
            loadedModelState.sharedMaterials.cobalt.emissiveIntensity = 0.22 + solidifyFlash * 0.34;
        }

        if (ui.copyEl && !loadedModelState.hasEmbeddedText) {
            ui.copyEl.classList.remove('hidden');
            ui.copyEl.classList.add('flex');
            const copyVisible = normT >= 0.54;
            ui.copyEl.style.opacity = copyVisible ? '1' : '0';
            ui.copyEl.style.transform = copyVisible ? 'translateY(0px)' : 'translateY(16px)';
        }

        // 3. Cincin gelombang kejut cyan halus saat mengunci solid (t norm 0.56 -> 0.82)
        const ringT = smoothstep(0.56, 0.82, normT);
        if (ringT > 0 && ringT < 1) {
            shockwaveMesh.visible = true;
            const rs = 0.7 + easeOutQuint(ringT) * 4.8;
            shockwaveMesh.scale.set(rs, rs, 1);
            shockwaveMat.opacity = (1 - ringT) * 0.44;
        } else {
            shockwaveMesh.visible = false;
        }

        // 4. Koreografi kamera, melayang halus (breathing), & mouse parallax independen FPS
        const camT = easeOutQuint(smoothstep(0.0, 0.78, normT));
        const zBase = 5.40 + (camera.userZoomOffset || 0);
        camera.position.set(
            (1 - camT) * 0.95,
            (1 - camT) * -0.32 + 0.02,
            zBase + (1 - camT) * 1.15,
        );
        camera.lookAt(0, 0.02, 0);

        const heroWeight = smoothstep(0.52, 0.76, normT);
        const damp = 1 - Math.exp(-dtSec * 7.5);
        pointerCurrentX += (pointerTargetX - pointerCurrentX) * damp;
        pointerCurrentY += (pointerTargetY - pointerCurrentY) * damp;
        logoRig.position.y = Math.sin(elapsedSec * 2.2) * 0.018 * heroWeight;
        logoRig.rotation.y = (1 - camT) * -0.24 + pointerCurrentX * 0.16 * heroWeight;
        logoRig.rotation.x = (1 - camT) * 0.10 + pointerCurrentY * 0.11 * heroWeight;

        renderer.render(scene, camera);

        if (elapsedMs >= TOTAL_DURATION_MS) {
            finishAndDispose(false);
            return;
        }

        rafId = requestAnimationFrame(tick);
    };

    rafId = requestAnimationFrame(tick);
    return true;
}

export function installSplashScreen() {
    // Tetap diekspor untuk kompatibilitas impor langsung
}
