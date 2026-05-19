import { useState, useEffect, useRef } from "react";
import { whatsapp, services } from "../data/site";

const P = { cyan: "#00B4D8", coral: "#E8573D" };
const WA_URL = whatsapp.url();
const titleCase = (s) => s.charAt(0).toUpperCase() + s.slice(1);

const REDUCED = typeof window !== "undefined"
  && window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;

export default function Servicios() {
  const [active, setActive] = useState(0);
  const [bulletActive, setBulletActive] = useState(0);
  const bulletPausedRef = useRef(false);
  const a = services[active];
  const audienceLabels = a.audienceLabels ?? a.audiences.map(titleCase);

  // Listen for the cross-component event fired by Hero floating service cards.
  useEffect(() => {
    const handler = (e) => {
      const slug = e?.detail?.slug;
      if (!slug) return;
      const idx = services.findIndex((s) => s.slug === slug);
      if (idx >= 0) setActive(idx);
    };
    window.addEventListener("fenia:select-service", handler);
    return () => window.removeEventListener("fenia:select-service", handler);
  }, []);

  // ADR-013 — Auto-cycle bullet highlight. Resets to 0 every time the
  // selected service tab changes. Paused while the user hovers any bullet.
  useEffect(() => {
    setBulletActive(0);
    if (REDUCED) return;
    const id = setInterval(() => {
      if (bulletPausedRef.current) return;
      setBulletActive((prev) => (prev + 1) % a.bullets.length);
    }, 2800);
    return () => clearInterval(id);
  }, [active, a.bullets.length]);

  return (
    <section id="servicios" style={{ background: "#0F2138", position: "relative", overflow: "hidden", fontFamily: "'Plus Jakarta Sans Variable','Plus Jakarta Sans',sans-serif" }}>
      <div style={{ position: "absolute", top: 0, left: 0, right: 0, height: 80, background: "linear-gradient(to bottom,#0B1A2E,transparent)", zIndex: 1, pointerEvents: "none" }} />
      <div style={{ maxWidth: 1200, margin: "0 auto", padding: "100px 48px", position: "relative", zIndex: 2 }} className="servicios-container">
        {/* Header */}
        <div className="reveal" style={{ textAlign: "center", marginBottom: 64 }}>
          <div style={{ display: "inline-flex", alignItems: "center", gap: 8, padding: "5px 14px", borderRadius: 100, border: "1px solid rgba(255,255,255,0.08)", marginBottom: 20 }}>
            <div style={{ width: 5, height: 5, borderRadius: "50%", background: P.cyan }} />
            <span style={{ fontSize: 11, fontWeight: 600, color: "rgba(255,255,255,0.5)", textTransform: "uppercase", letterSpacing: "0.1em" }}>Servicios</span>
          </div>
          <h2 style={{ fontSize: 38, fontWeight: 800, lineHeight: 1.15, color: "#fff", letterSpacing: "-0.03em", margin: "0 auto 14px", maxWidth: 600 }}>Soluciones estratégicas para cada necesidad</h2>
          <p style={{ fontSize: 16, lineHeight: 1.6, color: "rgba(255,255,255,0.6)", maxWidth: 480, margin: "0 auto" }}>Cada servicio articula neurociencia, estrategia e IA aplicada para generar impacto real</p>
        </div>

        {/* Tabs + Detail */}
        <div className="servicios-grid reveal">
          {/* Tabs (column on desktop, horizontal-scroll row on mobile — see global.css) */}
          <div className="servicios-tabs" role="tablist" aria-label="Lista de servicios">
            {services.map((s, i) => (
              <button
                key={s.slug}
                role="tab"
                aria-selected={active === i}
                onClick={() => setActive(i)}
                className={`servicios-tab ${active === i ? "is-active" : ""}`}
              >
                {active === i && <div className="servicios-tab-rule" />}
                <div className="servicios-tab-icon" aria-hidden="true">{s.icon}</div>
                <div className="servicios-tab-text">
                  <div className="servicios-tab-title">{s.title}</div>
                  <div className="servicios-tab-sub">{s.subtitle}</div>
                </div>
              </button>
            ))}
          </div>

          {/* Detail panel */}
          <div className="servicios-detail" role="tabpanel">
            <div className="servicios-detail-head">
              <div className="servicios-detail-icon" aria-hidden="true">{a.icon}</div>
              <h3 className="servicios-detail-title">{a.title}</h3>
            </div>
            <p className="servicios-detail-body">{a.body}</p>

            <div
              className="svc-bullets"
              onMouseLeave={() => { bulletPausedRef.current = false; }}
            >
              {a.bullets.map((b, i) => (
                <div
                  key={b}
                  className={`svc-bullet ${i === bulletActive ? "is-active" : ""}`}
                  onMouseEnter={() => {
                    bulletPausedRef.current = true;
                    setBulletActive(i);
                  }}
                >
                  <span className="svc-bullet-dot" aria-hidden="true" />
                  <span className="svc-bullet-text">{b}</span>
                </div>
              ))}
            </div>

            <div className="servicios-detail-foot">
              <div className="servicios-detail-chips">
                {audienceLabels.map((label) => (
                  <span key={label} className="servicios-detail-chip">{label}</span>
                ))}
              </div>
              <a className="cta-glow cta-magnetic servicios-detail-cta" href={WA_URL} target="_blank" rel="noopener noreferrer">Consultar →</a>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
