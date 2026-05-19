/**
 * NeuralNetwork3D — Pack 4 MVP.
 *
 * WebGL-rendered version of the FENIA neural network. Replaces the static
 * inline SVG that decorated the Hero. Driven by three.js via @react-three/fiber.
 *
 * Visual contract:
 *  - ~12 spherical nodes positioned with Z depth.
 *  - Connecting lines between selected node pairs.
 *  - Each node pulses subtly (scale wobble with phase offset).
 *  - The whole group eases toward the cursor for a parallax effect.
 *  - Reduced-motion: scene renders static (no useFrame updates).
 *
 * This is v1 — intentionally minimal. Future iterations could add:
 *  - Particles traveling along lines (light pulses).
 *  - Post-processing bloom.
 *  - Audio-reactive variant.
 *
 * Loaded as an Astro island with `client:visible` so the WebGL context
 * spins up only when the Hero is on-screen.
 */

import { Canvas, useFrame } from '@react-three/fiber';
import { useRef, useMemo, useEffect } from 'react';

// Track normalized window-wide pointer (-1..1) regardless of which element the cursor hovers.
function useWindowPointer() {
  const pointer = useRef({ x: 0, y: 0 });
  useEffect(() => {
    const handler = (e) => {
      pointer.current.x = (e.clientX / window.innerWidth) * 2 - 1;
      pointer.current.y = -((e.clientY / window.innerHeight) * 2 - 1);
    };
    window.addEventListener('mousemove', handler, { passive: true });
    return () => window.removeEventListener('mousemove', handler);
  }, []);
  return pointer;
}

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
      {/* Inner solid core */}
      <mesh ref={ref}>
        <sphereGeometry args={[size, 20, 20]} />
        <meshBasicMaterial color="#00B4D8" transparent opacity={0.85} />
      </mesh>
      {/* Outer halo */}
      <mesh>
        <sphereGeometry args={[size * 2.4, 16, 16]} />
        <meshBasicMaterial color="#00B4D8" transparent opacity={0.08} />
      </mesh>
    </group>
  );
}

function NetworkLines() {
  const positions = useMemo(() => {
    const arr = [];
    LINES.forEach(([a, b]) => {
      arr.push(...NODES[a].pos, ...NODES[b].pos);
    });
    return new Float32Array(arr);
  }, []);
  return (
    <lineSegments>
      <bufferGeometry>
        <bufferAttribute
          attach="attributes-position"
          array={positions}
          count={positions.length / 3}
          itemSize={3}
        />
      </bufferGeometry>
      <lineBasicMaterial color="#00B4D8" transparent opacity={0.18} />
    </lineSegments>
  );
}

// Pack 4 v1.1: small light-pulses traveling along randomly chosen lines.
// `count` simultaneous pulses, each picks a new line when it finishes traversing.
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
        // Restart at a new random line after a brief pause
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
      // Fade in/out at the edges of the travel
      const edge = Math.min(progress, 1 - progress) * 4;
      mesh.material.opacity = Math.min(1, edge);
    });
  });

  return (
    <>
      {Array.from({ length: count }).map((_, i) => (
        <mesh key={i} ref={(el) => (meshes.current[i] = el)} visible={false}>
          <sphereGeometry args={[0.05, 12, 12]} />
          <meshBasicMaterial color="#7DEAFF" transparent opacity={0} />
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
    // Mouse parallax: smooth easing toward window-wide pointer
    const targetRotY = pointer.current.x * 0.22;
    const targetRotX = pointer.current.y * 0.16;
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
  return (
    <Canvas
      camera={{ position: [0, 0, 5.4], fov: 50 }}
      dpr={[1, 2]}
      style={{ width: '100%', height: '100%', display: 'block', background: 'transparent', pointerEvents: 'none' }}
      gl={{ antialias: true, alpha: true, powerPreference: 'high-performance' }}
      events={undefined}
    >
      <Scene />
    </Canvas>
  );
}
