/*
 * Pharos not found (errors/404.blade.php): the sign in wall as a relief. The
 * cells are the uptime cells of the sign in screen; the ones under the digits
 * stand out of the wall in the brand colour, and a check sweeps across every
 * few seconds. Colours are read from the page's tokens, so a brand pack and
 * the dark theme carry over without a rebuild.
 *
 * Built with `npm run build:not-found` into public/assets/not-found/scene.js,
 * which ships with the release. Decoration only: when WebGL is missing the
 * flat wall in the markup stays, and with reduced motion one still frame is drawn.
 */
import {
  AmbientLight, BoxGeometry, Color, DirectionalLight, DynamicDrawUsage, InstancedBufferAttribute, InstancedMesh,
  Mesh, MeshLambertMaterial, PCFShadowMap, PerspectiveCamera, Plane, PlaneGeometry, Raycaster, Scene,
  ShadowMaterial, Vector2, Vector3, WebGLRenderer,
} from 'three';

const CELL = { x: 0.44, y: 0.836, fill: 0.84 };   // pitch, and the share of it a cell covers (1 : 1.9, as on the sign in wall)
const COLS = 116, ROWS = 68;
const TEXT = { say: '404', width: 31, weight: 800, family: "'Plus Jakarta Sans', system-ui, sans-serif" };
const DEPTH = { field: 0.22, type: 1.7 };
const RIM = { x: 23, y: 28, from: 0.74 };           // cells shrink to nothing towards this ellipse
const VIEW = { fov: 24, needW: 44, needH: 21, yaw: -0.16, pitch: 0.09 };
const SCAN = { every: 7, takes: 2.6, width: 1.5 };
const RISE = { takes: 1.3, spread: 0.9 };

const smooth = (t) => t * t * (3 - 2 * t);
const clamp = (t) => Math.min(1, Math.max(0, t));
// Mixed the way CSS mixes them: Color holds linear values, where a little white is a lot.
const mix = (a, b, t) => a.clone().convertLinearToSRGB().lerp(b.clone().convertLinearToSRGB(), t).convertSRGBToLinear();

// How much of each cell the digits cover, read from the brand typeface itself.
function coverage() {
  const scale = 10, cw = CELL.x * scale, ch = CELL.y * scale;
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(COLS * cw);
  canvas.height = Math.round(ROWS * ch);
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  const font = (size) => { ctx.font = TEXT.weight + ' ' + size + 'px ' + TEXT.family; };
  font(100);
  font((100 * TEXT.width * scale) / ctx.measureText(TEXT.say).width);
  const box = ctx.measureText(TEXT.say);
  ctx.textAlign = 'center';
  ctx.fillText(TEXT.say, canvas.width / 2, canvas.height / 2 + (box.actualBoundingBoxAscent - box.actualBoundingBoxDescent) / 2);
  const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
  const cover = new Float32Array(COLS * ROWS);
  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      const x0 = Math.round(c * cw), x1 = Math.round((c + 1) * cw), y0 = Math.round(r * ch), y1 = Math.round((r + 1) * ch);
      let sum = 0;
      for (let y = y0; y < y1; y++) { for (let x = x0; x < x1; x++) { sum += pixels[(y * canvas.width + x) * 4 + 3]; } }
      cover[r * COLS + c] = smooth(clamp((sum / ((x1 - x0) * (y1 - y0) * 255) - 0.3) / 0.4));
    }
  }
  return cover;
}

function readTheme(el) {
  const probe = document.createElement('span');
  probe.style.display = 'none';
  el.appendChild(probe);
  const pick = (name, fallback) => {
    probe.style.color = '';
    probe.style.color = 'var(' + name + ')';
    const parts = (getComputedStyle(probe).color.match(/[\d.]+/g) || []).map(Number);
    return parts.length >= 3 && probe.style.color ? new Color().setRGB(parts[0] / 255, parts[1] / 255, parts[2] / 255, 'srgb') : new Color(fallback);
  };
  const theme = { bg: pick('--pa-tint', '#f2f6fb'), off: pick('--pa-cell-off', '#e9eef4'), brand: pick('--brand', '#0079d2'), ink: pick('--ink', '#0e1726') };
  probe.remove();
  const hsl = {};
  theme.bg.getHSL(hsl);
  theme.dark = hsl.l < 0.4;
  return theme;
}

function mount(wall) {
  const canvas = wall.querySelector('canvas');
  const renderer = new WebGLRenderer({ canvas, antialias: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = PCFShadowMap;
  const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const scene = new Scene();
  const camera = new PerspectiveCamera(VIEW.fov, 1, 1, 400);
  const aim = new Vector3(0, 0, 1);
  // Lit so a cell facing us shows its token colour; sides and shadows fall below it.
  scene.add(new AmbientLight(0xffffff, 2.3));
  const sun = new DirectionalLight(0xffffff, 1.1);
  sun.position.set(-36, 56, 80);
  sun.castShadow = true;
  sun.shadow.mapSize.set(2048, 2048);
  Object.assign(sun.shadow.camera, { left: -34, right: 34, top: 36, bottom: -36, near: 10, far: 220 });
  sun.shadow.bias = -0.0005;
  sun.shadow.normalBias = 0.04;
  scene.add(sun);

  const count = COLS * ROWS, cover = coverage();
  const px = new Float32Array(count), py = new Float32Array(count), size = new Float32Array(count), wait = new Float32Array(count);
  for (let r = 0, i = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++, i++) {
      px[i] = (c - (COLS - 1) / 2) * CELL.x;
      py[i] = ((ROWS - 1) / 2 - r) * CELL.y;
      size[i] = 1 - smooth(clamp((Math.hypot(px[i] / RIM.x, py[i] / RIM.y) - RIM.from) / (1 - RIM.from)));
      wait[i] = clamp((px[i] + TEXT.width / 2) / TEXT.width) * RISE.spread;
    }
  }
  const cells = new InstancedMesh(new BoxGeometry(1, 1, 1), new MeshLambertMaterial(), count);
  cells.instanceMatrix.setUsage(DynamicDrawUsage);
  cells.instanceColor = new InstancedBufferAttribute(new Float32Array(count * 3), 3);
  cells.instanceColor.setUsage(DynamicDrawUsage);
  cells.castShadow = cells.receiveShadow = true;
  cells.frustumCulled = false;
  scene.add(cells);
  // Catches the shadows in the gaps between the cells; draws nothing else.
  const back = new Mesh(new PlaneGeometry(COLS * CELL.x, ROWS * CELL.y), new ShadowMaterial({ opacity: 0.1 }));
  back.receiveShadow = true;
  scene.add(back);

  let theme, field, type, glint;
  const applyTheme = () => {
    theme = readTheme(wall);
    scene.background = theme.bg;
    field = mix(theme.off, theme.ink, theme.dark ? 0.05 : 0.04);
    type = theme.brand;
    glint = mix(theme.brand, new Color('#ffffff'), 0.55);
    back.material.opacity = theme.dark ? 0.3 : 0.1;
  };
  applyTheme();

  const pointer = { ndc: new Vector2(), ease: new Vector2(), inside: 0, pull: 0, hit: new Vector3() };
  const ray = new Raycaster(), face = new Plane(new Vector3(0, 0, 1), -1.5);
  let distance = 100;

  const resize = () => {
    const w = wall.clientWidth, h = wall.clientHeight;
    if (!w || !h) { return; }
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    const t = Math.tan((VIEW.fov * Math.PI) / 360);
    distance = Math.max(VIEW.needW / (2 * t * camera.aspect), VIEW.needH / (2 * t));
    camera.updateProjectionMatrix();
  };

  const draw = (seconds, delta) => {
    pointer.ease.lerp(pointer.ndc, still ? 1 : Math.min(1, delta * 3.5));
    pointer.pull += (pointer.inside - pointer.pull) * Math.min(1, delta * 5);
    const yaw = VIEW.yaw + pointer.ease.x * 0.07, pitch = VIEW.pitch + pointer.ease.y * 0.045;
    camera.position.set(aim.x + distance * Math.sin(yaw) * Math.cos(pitch), aim.y + distance * Math.sin(pitch), aim.z + distance * Math.cos(yaw) * Math.cos(pitch));
    camera.lookAt(aim);
    camera.updateMatrixWorld();
    ray.setFromCamera(pointer.ndc, camera);
    ray.ray.intersectPlane(face, pointer.hit);

    const phase = seconds % SCAN.every, edge = COLS * CELL.x * 0.36;
    const scanX = !still && seconds > RISE.takes + RISE.spread && phase < SCAN.takes ? -edge + (2 * edge * phase) / SCAN.takes : Infinity;
    const matrices = cells.instanceMatrix.array, colours = cells.instanceColor.array;
    for (let i = 0; i < count; i++) {
      const s = size[i], m = i * 16, c = i * 3, k = cover[i];
      const risen = still ? 1 : 1 - Math.pow(1 - clamp((seconds - wait[i]) / RISE.takes), 3);
      const swell = still ? 0 : 0.09 * Math.sin(px[i] * 0.45 + seconds * 0.8) * Math.sin(py[i] * 0.3 - seconds * 0.5) * (1 - k);
      const sweep = Math.exp(-(((px[i] - scanX) / SCAN.width) ** 2));
      const dx = px[i] - pointer.hit.x, dy = py[i] - pointer.hit.y;
      const near = pointer.pull * Math.exp(-(dx * dx + dy * dy) / 5);
      const depth = DEPTH.field + k * DEPTH.type * risen + swell + sweep * (0.12 + 0.3 * k) + near * 0.5;

      matrices[m] = s * CELL.x * CELL.fill; matrices[m + 5] = s * CELL.y * CELL.fill; matrices[m + 10] = s > 0 ? depth : 0; matrices[m + 15] = 1;
      matrices[m + 12] = px[i]; matrices[m + 13] = py[i]; matrices[m + 14] = depth / 2;

      const ink = k * risen, light = sweep * (0.1 + 0.5 * k) + near * 0.16, rim = 1 - s;
      let red = field.r + (type.r - field.r) * ink, green = field.g + (type.g - field.g) * ink, blue = field.b + (type.b - field.b) * ink;
      red += (glint.r - red) * light; green += (glint.g - green) * light; blue += (glint.b - blue) * light;
      colours[c] = red + (theme.bg.r - red) * rim;
      colours[c + 1] = green + (theme.bg.g - green) * rim;
      colours[c + 2] = blue + (theme.bg.b - blue) * rim;
    }
    cells.instanceMatrix.needsUpdate = true;
    cells.instanceColor.needsUpdate = true;
    renderer.render(scene, camera);
  };

  let started = 0, last = 0;
  const tick = (now) => { started = started || now; const seconds = (now - started) / 1000; draw(seconds, Math.min(0.1, seconds - last)); last = seconds; };
  const once = () => draw(0, 1);

  resize();
  new ResizeObserver(() => { resize(); if (still) { once(); } }).observe(wall);
  new MutationObserver(() => { applyTheme(); if (still) { once(); } }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { applyTheme(); if (still) { once(); } });

  if (still) {
    once();
  } else {
    wall.addEventListener('pointermove', (event) => {
      const box = wall.getBoundingClientRect();
      pointer.ndc.set(((event.clientX - box.left) / box.width) * 2 - 1, -(((event.clientY - box.top) / box.height) * 2 - 1));
      pointer.inside = 1;
    });
    wall.addEventListener('pointerleave', () => { pointer.inside = 0; pointer.ndc.set(0, 0); });
    renderer.setAnimationLoop(tick);
  }
  wall.classList.add('has-scene');
}

// The digits are cut from the brand typeface, so wait for it (briefly) first.
const ready = document.fonts && document.fonts.load ? Promise.race([document.fonts.load(TEXT.weight + ' 100px ' + TEXT.family), new Promise((done) => setTimeout(done, 1500))]) : Promise.resolve();
ready.catch(() => {}).then(() => {
  document.querySelectorAll('[data-nf-scene]').forEach((wall) => {
    // No WebGL, or anything else going wrong: the flat wall underneath stays.
    try { mount(wall); } catch (error) { console.warn('Pharos 404 scene not started:', error); }
  });
});
