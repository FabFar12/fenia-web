// Attacks on the sales API (ADR-028). Written as `todo` until the endpoints
// exist; each one becomes a real test in the step that builds its endpoint.
// IDs refer to docs/ventas/test-plan.md and docs/ventas/threat-model.md.
import { describe, it } from 'node:test';

const STEP_32 = 'paso 3.2 (endpoints)';
const STEP_42 = 'paso 4.2 (frontend)';

describe('Webhook', () => {
  it('S-01 ✱ POST sin firma → 401 sin efectos', { todo: STEP_32 });
  it('S-02 ✱ POST con firma inventada → 401', { todo: STEP_32 });
  it('S-03 firma válida + pago inexistente → 200 sin entrega (staging)', { todo: STEP_32 });
  it('S-04 el mismo aviso real 5 veces → una sola entrega (staging)', { todo: STEP_32 });
});

describe('Estado de la orden y /gracias', () => {
  it('S-05 ✱ pago ajeno o código de orden incorrecto → sin link', { todo: STEP_32 });
  it('S-06 ✱ /gracias?status=approved escrito a mano → sin botón de descarga', { todo: STEP_42 });
});

describe('Descarga', () => {
  it('S-07 ✱ 1.000 tokens aleatorios → todos rechazados igual', { todo: STEP_32 });
  it('S-08 ✱ N intentos fallidos desde una IP → 429', { todo: STEP_32 });
  it('S-10 ✱ path traversal (../, codificado, doble codificado) → 400/404', { todo: STEP_32 });
  it('S-13 sexta descarga → rechazada (staging)', { todo: STEP_32 });
  it('S-14 link vencido → rechazado (staging)', { todo: STEP_32 });
  it('S-18 GET/HEAD repetidos al link del mail → contador intacto (staging)', { todo: STEP_32 });
});

describe('Checkout', () => {
  it('S-12 campos price/amount/unit_price en el POST → ignorados (staging)', { todo: STEP_32 });
  it('S-16 más de N checkouts por IP en una hora → 429 (staging)', { todo: STEP_32 });
  it('S-17 ✱ campo trampa completado → rechazado sin crear nada', { todo: STEP_32 });
  it('S-19 ✱ kit coming-soon / hidden / inexistente → 404 sin crear nada', { todo: STEP_32 });
  it('S-21 ✱ email con \\r\\n → rechazado', { todo: STEP_32 });
  it('S-23 ✱ POST desde otro origen → rechazado', { todo: STEP_32 });
});

describe('No me llegó', () => {
  it('S-15 ✱ email sin compras → misma respuesta, sin mail', { todo: STEP_32 });
});

describe('Inyección', () => {
  it('S-20 ✱ payloads SQL en todos los campos → sin 500 y sin cambios', { todo: STEP_32 });
});
