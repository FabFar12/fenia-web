<!--
Antes de pedir review, revisá esta plantilla. La mayoría es mecánico.
Contexto completo: AGENTS.md (raíz del repo).
-->

## Qué hace este PR

<!-- 1-3 líneas. Si referencia un ADR, linkealo (ej. [ADR-022](docs/adr/ADR-022-pivot-to-hostinger-only.md)). -->

## Tipo

- [ ] `feat` — nueva funcionalidad / sección
- [ ] `fix` — corrige un bug
- [ ] `tweak` — refinamiento de algo existente
- [ ] `chore` — infra / deps / config / docs
- [ ] `refactor` — sin cambio funcional visible

## Checklist

- [ ] `npm run check` verde localmente
- [ ] `npm run build` verde localmente
- [ ] Si toca contenido (WhatsApp, redes, KPIs, productos, testimonios): edité `src/data/site.ts` o `src/content/`, **no** un componente. (AGENTS.md § content model)
- [ ] Si es cambio visual (colores, layout, animaciones, copy visible): propuesta escrita + aprobación humana **antes** de implementar. (AGENTS.md § PROPOSE-THEN-EXECUTE)
- [ ] Si introduce decisión técnica nueva: ADR en `docs/adr/` linkeada arriba.
- [ ] Si afecta deploy / hosting / `.htaccess` / CSP / cache: validado en staging antes de mergear a `main`.
- [ ] Si introduce dependency nueva: justificación en la descripción.

## Validación manual

<!-- Pasos / URLs / screenshots para que el revisor confirme la prueba en navegador real. Opcional pero ahorra rondas. -->

## Notas para el revisor

<!-- Cosas que mirar con cuidado, dudas abiertas, follow-ups planeados. Opcional. -->
