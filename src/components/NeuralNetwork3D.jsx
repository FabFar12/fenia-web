/**
 * NeuralNetwork3D — Pack 4 v1.2 + Pack v2 polish.
 *
 * WebGL-rendered version of the FENIA neural network. Loaded as an Astro
 * island with `client:visible` so the WebGL context spins up only when the
 * Hero is on-screen.
 *
 * Visual contract:
 *  - ~12 spherical nodes positioned with Z depth, each with inner core +
 *    outer halo. Cores pulse with phase-offset sine waves.
 *  - 16 connecting lines between selected pairs, drawn with a per-vertex
 *    cyan→deep-cyan gradient (v2.3).
 *  - Group eases toward the window-wide pointer (parallax).
 *  - **v2.2 — Idle camera drift**: when no pointer movement for 5 s, the
 *    group slowly orbits on Y axis. Wakes back to parallax on cursor move.
 *  - **v2.1 — Bloom post-processing**: cores use `toneMapped={false}` with
 *    saturated cyan so EffectComposer's Bloom catches them and produces a
 *    soft halo bleeding into the surrounding navy. Documented in ADR-011.
 *  - 3 flow-pulses travel along random lines (v1.1).
 *
 * Reduced-motion: scene renders but no useFrame mutations (static frame).
 */

import { Canvas, useFrame } from '@react-three/fiber';
import { EffectComposer, Bloom } from '@react-three/postprocessing';
import { useRef, useMemo, useEffect, useState } from 'react';

const NODES = [
  { pos: [-2.1, 1.2, 0.2], size: 0.06 },
  { pos: [-1.3, 0.3, 0.5], size: 0.08 },
  { pos: [-0.4, 1.5, 0.0], size: 0.05 },
  { pos: [0.4, 0.8, 0.4], size: 0.10 },
  { pos: [1.3, 1.8, 0.1], size: 0.06 },
  { pos: [1.9, 0.5, -0.2], size: 0.08 },
  { pos: [0.0, -0.6, 0.3], size: 0.07 },
  { pos: [-1.1, -1.2, 0.1], size: 0.06 },
  { pos: [1.2, -1.3, 0.4], size: 0.07 },
  { pos: [2.3, -0.6, 0.0], size: 0.05 },
  { pos: [-2.4, -0.2, -0.1], size: 0.05 },
  { pos: [0.6, 2.0, -0.3], size: 0.04 },
];

const LINES = [
  [0, 1], [1, 2], [1, 3], [2, 3], [3, 4], [3, 5], [4, 5], [5, 9],
  [1, 6], [6, 7], [6, 8], [8, 9], [7, 10], [0, 10], [2, 11], [4, 11],
];

const REDUCED = typeof window !== 'undefined'
  && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

// Track normalized window-wide pointer (-1..1) for parallax, plus the last
// timestamp the pointer moved (for idle drift detection).
function useWindowPointer() {
  const pointer = useRef({ x: 0, y: 0, lastMove: 0 });
  useEffect(() => {
    const handler = (e) => {
      pointer.current.x = (e.clientX / window.innerWidth) * 2 - 1;
      pointer.current.y = -((e.clientY / window.innerHeight) * 2 - 1);
      pointer.current.lastMove = performance.now();
    };
    window.addEventListener('mousemove', handler, { passive: true });
    return () => window.removeEventListener('mousemove', handler);
  }, []);
  return pointer;
}

function Node({ position, size, delay }) {
  const ref = useRef();
  useFrame((state) => {
    if (REDUCED || !ref.current) return;
    const t = state.clock.elapsedTime;
    const pulse = 1 + 0.18 * Math.sin(t * 1.3 + delay);
    ref.current.scale.setScalar(pulse);
  });
  return (
    <group position={position}>
      {/* Inner solid core — bright + toneMapped=false so Bloom catches it */}
      <mesh ref={ref}>
        <sphereGeometry args={[size, 20, 20]} />
        <meshBasicMaterial color="#1FE3FF" toneMapped={false} transparent opacity={0.95} />
      </mesh>
      {/* Outer subtle halo (rendered after the core so transparency stacks correctly) */}
      <mesh>
        <sphereGeometry args={[size * 2.4, 16, 16]} />
        <meshBasicMaterial color="#00B4D8" transparent opacity={0.10} />
      </mesh>
    </group>
  );
}

// v2.3 — Lines with per-vertex color gradient (cyan → deep cyan).
function NetworkLines() {
  const { positions, colors } = useMemo(() => {
    const pos = [];
    const col = [];
    LINES.forEach(([a, b]) => {
      pos.push(...NODES[a].pos, ...NODES[b].pos);
      // Start: brighter cyan; End: deeper teal.
      col.push(0.12, 0.85, 1.0); // #1FD9FF-ish
      col.push(0.0, 0.45, 0.65); // #00738B-ish
    });
    return { positions: new Float32Array(pos), colors: new Float32Array(col) };
  }, []);
  return (
    <lineSegments>
      <bufferGeometry>
        <bufferAttribute attach="attributes-position" array={positions} count={positions.length / 3} itemSize={3} />
        <bufferAttribute attach="attributes-color" array={colors} count={colors.length / 3} itemSize={3} />
      </bufferGeometry>
      <lineBasicMaterial vertexColors transparent opacity={0.45} toneMapped={false} />
    </lineSegments>
  );
}

// Pack 4 v1.1 — Small light-pulses traveling along randomly chosen lines.
function FlowPulses({ count = 3 }) {
  const meshes = useRef([]);
  const pulses = useRef(
    Array.from({ length: count }, () => ({
      lineIdx: Math.floor(Math.random() * LINES.length),
      startTime: Math.random() * 3,
      duration: 1.4 + Math.random() * 0.8,
      gap: 0.5 + Math.random() * 1.5,
    })),
  );

  useFrame((state) => {
    if (REDUCED) return;
    const t = state.clock.elapsedTime;
    pulses.current.forEach((p, i) => {
      const mesh = meshes.current[i];
      if (!mesh) return;
      const elapsed = t - p.startTime;
      if (elapsed >= p.duration + p.gap) {
        p.lineIdx = Math.floor(Math.random() * LINES.length);
        p.startTime = t;
        p.duration = 1.4 + Math.random() * 0.8;
        p.gap = 0.5 + Math.random() * 1.5;
        mesh.visible = false;
        return;
      }
      if (elapsed < 0 || elapsed > p.duration) {
        mesh.visible = false;
        return;
      }
      const progress = elapsed / p.duration;
      const [a, b] = LINES[p.lineIdx];
      const start = NODES[a].pos;
      const end = NODES[b].pos;
      mesh.position.set(
        start[0] + (end[0] - start[0]) * progress,
        start[1] + (end[1] - start[1]) * progress,
        start[2] + (end[2] - start[2]) * progress,
      );
      mesh.visible = true;
      const edge = Math.min(progress, 1 - progress) * 4;
      mesh.material.opacity = Math.min(1, edge);
    });
  });

  return (
    <>
      {Array.from({ length: count }).map((_, i) => (
        <mesh key={i} ref={(el) => (meshes.current[i] = el)} visible={false}>
          <sphereGeometry args={[0.05, 12, 12]} />
          <meshBasicMaterial color="#7DEAFF" toneMapped={false} transparent opacity={0} />
        </mesh>
      ))}
    </>
  );
}

function Scene() {
  const groupRef = useRef();
  const pointer = useWindowPointer();

  useFrame(() => {
    if (REDUCED || !groupRef.current) return;
    const now = performance.now();
    const idleMs = now - pointer.current.lastMove;
    const IDLE_THRESHOLD = 5000;

    // Parallax target from window pointer
    let targetRotY = pointer.current.x * 0.22;
    const targetRotX = pointer.current.y * 0.16;

    // v2.2 — Idle camera drift: once idle for >5 s, slowly add Y rotation.
    if (idleMs > IDLE_THRESHOLD) {
      // Smoothly ramp the drift intensity over the next 1 s of idle.
      const ramp = Math.min((idleMs - IDLE_THRESHOLD) / 1000, 1);
      targetRotY += ramp * 0.0006 * (now / 16); // accumulates over time
      // Wrap so we don't grow unbounded
      targetRotY = ((targetRotY + Math.PI) % (Math.PI * 2)) - Math.PI;
    }

    groupRef.current.rotation.y += (targetRotY - groupRef.current.rotation.y) * 0.04;
    groupRef.current.rotation.x += (targetRotX - groupRef.current.rotation.x) * 0.04;
  });

  return (
    <group ref={groupRef}>
      <NetworkLines />
      {NODES.map((n, i) => (
        <Node key={i} position={n.pos} size={n.size} delay={i * 0.7} />
      ))}
      <FlowPulses count={3} />
    </group>
  );
}

export default function NeuralNetwork3D() {
  // ADR-012 — Pause `useFrame` (and therefore the GPU + Bloom pipeline) once
  // the Hero is scrolled out of the viewport. Switching `frameloop` to
  // 'never' suspends the render loop entirely; switching back to 'always'
  // resumes it. Resolves the scroll-lag bug reported on 2026-05-19 — when
  // the 3D scene was always running it competed with the main thread on
  // every scroll tick.
  const wrapperRef = useRef(null);
  const [inView, setInView] = useState(true);

  useEffect(() => {
    if (!wrapperRef.current || !('IntersectionObserver' in window)) return;
    const io = new IntersectionObserver(
      ([entry]) => setInView(entry.isIntersecting),
      { threshold: 0, rootMargin: '20% 0px 20% 0px' },
    );
    io.observe(wrapperRef.current);
    return () => io.disconnect();
  }, []);

  return (
    <div ref={wrapperRef} style={{ width: '100%', height: '100%' }}>
      <Canvas
        camera={{ position: [0, 0, 5.4], fov: 50 }}
        dpr={[1, 2]}
        frameloop={inView ? 'always' : 'never'}
        style={{ width: '100%', height: '100%', display: 'block', background: 'transparent', pointerEvents: 'none' }}
        gl={{ antialias: true, alpha: true, powerPreference: 'high-performance' }}
        events={undefined}
      >
        <Scene />
        {/* v2.1 — Bloom post-processing for premium glow. */}
        <EffectComposer enableNormalPass={false}>
          <Bloom
            intensity={1.1}
            luminanceThreshold={0.35}
            luminanceSmoothing={0.55}
            mipmapBlur
            radius={0.7}
          />
        </EffectComposer>
      </Canvas>
    </div>
  );
}
