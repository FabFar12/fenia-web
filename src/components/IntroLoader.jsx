import { useState, useRef, useEffect } from "react";

export default function IntroLoader() {
  const [visible, setVisible] = useState(true);
  const [fading, setFading] = useState(false);
  const videoRef = useRef(null);

  useEffect(() => {
    const video = videoRef.current;
    if (!video) return;

    video.play().catch(() => {
      setFading(true);
      setTimeout(() => setVisible(false), 800);
    });

    video.addEventListener("ended", () => {
      setTimeout(() => {
        setFading(true);
        setTimeout(() => setVisible(false), 800);
      }, 400);
    });

    const timeout = setTimeout(() => {
      setFading(true);
      setTimeout(() => setVisible(false), 800);
    }, 7000);

    return () => clearTimeout(timeout);
  }, []);

  if (!visible) return null;

  return (
    <div style={{
      position:"fixed",top:0,left:0,width:"100vw",height:"100vh",
      background:"#0B1A2E",display:"flex",alignItems:"center",justifyContent:"center",
      zIndex:9999,opacity:fading?0:1,transition:"opacity 0.8s ease"
    }}>
      <video ref={videoRef} src="/images/intro-fenia.mp4" muted playsInline
        style={{ maxWidth:"560px",width:"90%",height:"auto" }} />
    </div>
  );
}
