import { useEffect, useRef } from 'react';
import { useAccess } from './AccessContext';

// three.js is not an npm dependency of the project yet (installing packages needs approval);
// it is loaded from the same CDN build the approved design used.
const THREE_SRC = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';

function loadThree() {
    if (window.THREE) return Promise.resolve(window.THREE);

    if (!window.__threeLoading) {
        window.__threeLoading = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = THREE_SRC;
            script.async = true;
            script.onload = () => resolve(window.THREE);
            script.onerror = () => {
                window.__threeLoading = null;
                reject(new Error('three.js could not be loaded'));
            };
            document.head.appendChild(script);
        });
    }

    return window.__threeLoading;
}

const PARTICLE_COUNT = 2000;

/**
 * Animated particle sphere that fills the viewport. Port of the approved reference background.
 * If three.js cannot be loaded (offline) the page simply keeps the CSS grid background.
 */
export default function ParticleBackground() {
    const { isDark, sphereRef, updateConnections } = useAccess();
    const containerRef = useRef(null);
    const applyThemeRef = useRef(null);

    useEffect(() => {
        applyThemeRef.current?.(isDark);
    }, [isDark]);

    useEffect(() => {
        let disposed = false;
        let frame = 0;
        let teardown = () => {};

        loadThree()
            .then((THREE) => {
                if (disposed || !containerRef.current) return;

                const container = containerRef.current;
                const scene = new THREE.Scene();
                const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
                const renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
                renderer.setSize(window.innerWidth, window.innerHeight);
                renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
                container.appendChild(renderer.domElement);

                const positions = new Float32Array(PARTICLE_COUNT * 3);
                const colors = new Float32Array(PARTICLE_COUNT * 3);
                const speeds = new Float32Array(PARTICLE_COUNT);
                const palette = [new THREE.Color(0x00f0ff), new THREE.Color(0xb44dff), new THREE.Color(0xff2d95)];

                for (let i = 0; i < PARTICLE_COUNT; i++) {
                    const theta = Math.random() * Math.PI * 2;
                    const phi = Math.acos(2 * Math.random() - 1);
                    const r = 2 + Math.random() * 0.5;

                    positions[i * 3] = r * Math.sin(phi) * Math.cos(theta);
                    positions[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
                    positions[i * 3 + 2] = r * Math.cos(phi);
                    speeds[i] = 0.002 + Math.random() * 0.005;

                    const choice = Math.random();
                    const color = choice < 0.33 ? palette[0] : choice < 0.66 ? palette[1] : palette[2];
                    colors[i * 3] = color.r;
                    colors[i * 3 + 1] = color.g;
                    colors[i * 3 + 2] = color.b;
                }

                const geometry = new THREE.BufferGeometry();
                geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
                geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));

                const material = new THREE.PointsMaterial({
                    size: 0.022,
                    vertexColors: true,
                    transparent: true,
                    opacity: 0.85,
                    blending: THREE.AdditiveBlending,
                    depthWrite: false,
                });

                const group = new THREE.Group();
                scene.add(group);

                const particles = new THREE.Points(geometry, material);
                group.add(particles);

                const innerGeo = new THREE.IcosahedronGeometry(1.5, 1);
                const innerMat = new THREE.MeshBasicMaterial({ color: 0x00f0ff, wireframe: true, transparent: true, opacity: 0.15 });
                const innerSphere = new THREE.Mesh(innerGeo, innerMat);
                group.add(innerSphere);

                const outerGeo = new THREE.IcosahedronGeometry(2.2, 0);
                const outerMat = new THREE.MeshBasicMaterial({ color: 0xff2d95, wireframe: true, transparent: true, opacity: 0.08 });
                const outerSphere = new THREE.Mesh(outerGeo, outerMat);
                group.add(outerSphere);

                camera.position.z = 5;

                // Scale the scene with the viewport aspect so it covers the whole screen.
                const fitBackground = () => {
                    const aspect = window.innerWidth / window.innerHeight;
                    group.scale.setScalar(Math.min(2, Math.max(1.4, aspect * 1.1)));
                };
                fitBackground();

                // Light mode: normal blending and a darker tint so the particles stay visible.
                applyThemeRef.current = (dark) => {
                    material.blending = dark ? THREE.AdditiveBlending : THREE.NormalBlending;
                    material.color.setScalar(dark ? 1 : 0.65);
                    material.needsUpdate = true;
                };
                applyThemeRef.current(document.documentElement.classList.contains('dark'));

                sphereRef.current = {
                    getSphereScreen: () => {
                        // Before the first rendered frame the camera matrices are still identity (w = 0 -> NaN).
                        camera.updateMatrixWorld();
                        const vector = innerSphere.position.clone().project(camera);

                        return {
                            x: (vector.x * 0.5 + 0.5) * window.innerWidth,
                            y: (-vector.y * 0.5 + 0.5) * window.innerHeight,
                        };
                    },
                };
                updateConnections();

                let mouseX = 0;
                let mouseY = 0;
                let visible = true;

                const onMouseMove = (e) => {
                    mouseX = (e.clientX / window.innerWidth - 0.5) * 2;
                    mouseY = (e.clientY / window.innerHeight - 0.5) * 2;
                };
                const onVisibility = () => {
                    visible = !document.hidden;
                };
                const onResize = () => {
                    camera.aspect = window.innerWidth / window.innerHeight;
                    camera.updateProjectionMatrix();
                    renderer.setSize(window.innerWidth, window.innerHeight);
                    fitBackground();
                    updateConnections();
                };

                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('visibilitychange', onVisibility);
                window.addEventListener('resize', onResize);

                const animate = () => {
                    frame = requestAnimationFrame(animate);
                    if (!visible) return;

                    const time = performance.now() * 0.001;

                    particles.rotation.y = time * 0.05;
                    particles.rotation.x = time * 0.02;

                    const pos = geometry.attributes.position.array;
                    for (let i = 0; i < PARTICLE_COUNT; i++) {
                        const idx = i * 3;
                        pos[idx + 1] += Math.sin(time + i * 0.1) * speeds[i] * 0.3;
                        pos[idx] += Math.cos(time + i * 0.15) * speeds[i] * 0.2;
                    }
                    geometry.attributes.position.needsUpdate = true;

                    innerSphere.rotation.x = time * 0.3;
                    innerSphere.rotation.z = time * 0.2;
                    innerSphere.scale.setScalar(1 + Math.sin(time * 1.5) * 0.05);

                    outerSphere.rotation.y = -time * 0.15;
                    outerSphere.rotation.z = time * 0.1;

                    camera.position.x += (mouseX * 0.5 - camera.position.x) * 0.02;
                    camera.position.y += (-mouseY * 0.5 - camera.position.y) * 0.02;
                    camera.lookAt(scene.position);

                    renderer.render(scene, camera);
                };
                animate();

                teardown = () => {
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('visibilitychange', onVisibility);
                    window.removeEventListener('resize', onResize);
                    [geometry, innerGeo, outerGeo].forEach((g) => g.dispose());
                    [material, innerMat, outerMat].forEach((m) => m.dispose());
                    renderer.dispose();
                    renderer.domElement.remove();
                    sphereRef.current = null;
                    applyThemeRef.current = null;
                };
            })
            .catch(() => {});

        return () => {
            disposed = true;
            cancelAnimationFrame(frame);
            teardown();
        };
        // The scene is created once per mount; theme changes go through applyThemeRef.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return <div id="three-container" ref={containerRef} />;
}
