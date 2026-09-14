/**
 * test_spec6_protocol.mjs — Corredor en terminal del protocolo de
 * verificación frontend de SPEC-06 (Tarea 5.3, plan 6.2).
 *
 * Ejecuta `scratch/protocol_elemental_codex.mjs` (los cinco casos del
 * plan 6.2) contra los MÓDULOS DE PRODUCCIÓN reales con artilugios
 * falsos, y emite el informe solemne con veredicto.
 *
 * Uso: node scratch/test_spec6_protocol.mjs
 */

import { runCodexProtocol } from './protocol_elemental_codex.mjs';

const report = await runCodexProtocol();

console.log('== PROTOCOLO DE VERIFICACIÓN FRONTEND — SPEC-06 (plan 6.2) ==');
for (const testCase of report.cases) {
  const state = testCase.passed ? 'SUPERADO' : 'FALLIDO';
  console.log(`\n[${testCase.caseId}] ${testCase.title} — ${state}`);
  for (const line of testCase.evidence) {
    console.log(`  · ${line}`);
  }
}
console.log(`\nVEREDICTO: ${report.verdict}`);
process.exit(report.verdict === 'COMPLETO' ? 0 : 1);
