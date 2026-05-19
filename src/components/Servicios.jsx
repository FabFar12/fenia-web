import { useState, useEffect } from "react";
import { whatsapp, services } from "../data/site";

const P = { cyan: "#00B4D8", coral: "#E8573D" };
const WA_URL = whatsapp.url();
const titleCase = (s) => s.charAt(0).toUpperCase() + s.slice(1);

export default function Servicios() {
  const [active, setActive] = useState(0);
  const a = services[active];
  const audienceLabels = a.audienceLabels ?? a.audiences.map(titleCase);

  // Listen for the cross-component event fired by Hero floating service cards.
  // When a user clicks one of those cards, this component selects the matching
  // tab so the corresponding service shows up after the smooth scroll.
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

  return (
    <section id="servicios" style={{ background:"#0F2138",position:"relative",overflow:"hidden",fontFamily:"'Plus Jakarta Sans',sans-serif" }}>
      <div style={{ position:"absolute",top:0,left:0,right:0,height:80,background:"linear-gradient(to bottom,#0B1A2E,transparent)",zIndex:1,pointerEvents:"none" }}/>
      <div style={{ maxWidth:1200,margin:"0 auto",padding:"100px 48px",position:"relative",zIndex:2 }}>
        {/* Header */}
        <div className="reveal" style={{ textAlign:"center",marginBottom:64 }}>
          <div style={{ display:"inline-flex",alignItems:"center",gap:8,padding:"5px 14px",borderRadius:100,border:"1px solid rgba(255,255,255,0.08)",marginBottom:20 }}>
            <div style={{ width:5,height:5,borderRadius:"50%",background:P.cyan }}/>
            <span style={{ fontSize:11,fontWeight:600,color:"rgba(255,255,255,0.4)",textTransform:"uppercase",letterSpacing:"0.1em" }}>Servicios</span>
          </div>
          <h2 style={{ fontSize:38,fontWeight:800,lineHeight:1.15,color:"#fff",letterSpacing:"-0.03em",margin:"0 auto 14px",maxWidth:600 }}>Soluciones estratégicas para cada necesidad</h2>
          <p style={{ fontSize:16,lineHeight:1.6,color:"rgba(255,255,255,0.45)",maxWidth:480,margin:"0 auto" }}>Cada servicio articula neurociencia, estrategia e IA aplicada para generar impacto real</p>
        </div>

        {/* Tabs + Detail */}
        <div className="reveal" style={{ display:"grid",gridTemplateColumns:"280px 1fr",gap:0,borderRadius:20,overflow:"hidden",border:"1px solid rgba(255,255,255,0.06)" }}>
          {/* Tabs */}
          <div style={{ background:"rgba(0,0,0,0.15)",padding:8 }} role="tablist">
            {services.map((s,i)=>(
              <button
                key={s.slug}
                role="tab"
                aria-selected={active === i}
                onClick={()=>setActive(i)}
                style={{ display:"flex",alignItems:"center",gap:14,width:"100%",padding:"20px 18px",borderRadius:12,border:"none",background:active===i?"rgba(0,180,216,0.08)":"transparent",cursor:"pointer",textAlign:"left",transition:"all 0.2s ease",position:"relative",fontFamily:"'Plus Jakarta Sans',sans-serif" }}
              >
                {active===i&&<div style={{ position:"absolute",left:0,top:"20%",bottom:"20%",width:3,borderRadius:2,background:P.cyan }}/>}
                <div style={{ width:36,height:36,borderRadius:10,background:active===i?"rgba(0,180,216,0.12)":"rgba(255,255,255,0.03)",border:`1px solid ${active===i?"rgba(0,180,216,0.25)":"rgba(255,255,255,0.06)"}`,display:"flex",alignItems:"center",justifyContent:"center",fontSize:15,color:active===i?P.cyan:"rgba(255,255,255,0.3)",flexShrink:0 }} aria-hidden="true">{s.icon}</div>
                <div><div style={{ fontSize:14,fontWeight:700,color:active===i?"#fff":"rgba(255,255,255,0.5)" }}>{s.title}</div><div style={{ fontSize:11,color:"rgba(255,255,255,0.3)",marginTop:2 }}>{s.subtitle}</div></div>
              </button>
            ))}
          </div>
          {/* Detail */}
          <div style={{ background:"rgba(255,255,255,0.02)",padding:"44px 48px" }} role="tabpanel">
            <div style={{ display:"flex",alignItems:"center",gap:14,marginBottom:20 }}>
              <div style={{ width:44,height:44,borderRadius:12,background:"rgba(0,180,216,0.1)",border:"1px solid rgba(0,180,216,0.2)",display:"flex",alignItems:"center",justifyContent:"center",fontSize:20,color:P.cyan }} aria-hidden="true">{a.icon}</div>
              <h3 style={{ fontSize:22,fontWeight:800,color:"#fff",margin:0 }}>{a.title}</h3>
            </div>
            <p style={{ fontSize:15,lineHeight:1.7,color:"rgba(255,255,255,0.5)",margin:"0 0 28px 0" }}>{a.body}</p>
            <div style={{ display:"flex",flexDirection:"column",gap:14,marginBottom:32 }}>
              {a.bullets.map((b,i)=>(
                <div key={b} style={{ display:"flex",alignItems:"flex-start",gap:12 }}>
                  <div style={{ width:6,height:6,borderRadius:"50%",background:i===0?P.cyan:"rgba(255,255,255,0.12)",marginTop:7,flexShrink:0 }} aria-hidden="true"/>
                  <span style={{ fontSize:14,lineHeight:1.5,color:"rgba(255,255,255,0.55)" }}>{b}</span>
                </div>
              ))}
            </div>
            <div style={{ display:"flex",alignItems:"center",justifyContent:"space-between",paddingTop:24,borderTop:"1px solid rgba(255,255,255,0.06)" }}>
              <div style={{ display:"flex",gap:8 }}>
                {audienceLabels.map((label)=>(
                  <span key={label} style={{ fontSize:11,fontWeight:600,padding:"4px 12px",borderRadius:6,background:"rgba(255,255,255,0.04)",border:"1px solid rgba(255,255,255,0.08)",color:"rgba(255,255,255,0.45)" }}>{label}</span>
                ))}
              </div>
              <a className="cta-glow cta-magnetic" href={WA_URL} target="_blank" rel="noopener noreferrer" style={{ fontSize:13,fontWeight:600,padding:"10px 24px",borderRadius:8,border:"none",background:P.coral,color:"#fff",cursor:"pointer",boxShadow:"0 4px 16px rgba(232,87,61,0.25)",textDecoration:"none" }}>Consultar →</a>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
