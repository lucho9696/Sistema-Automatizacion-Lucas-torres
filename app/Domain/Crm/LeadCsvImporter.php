<?php

namespace App\Domain\Crm;

use App\Domain\Shared\Auditor;
use App\Domain\Shared\BusinessRuleException;
use App\Models\ConsentRecord;
use App\Models\LeadImport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Importación CSV de prospectos (listas propias o adquiridas legalmente).
 * Cada fila pasa por las mismas reglas que el alta manual: dedupe, supresión y procedencia.
 * Con dry_run se valida todo dentro de una transacción que se revierte.
 */
class LeadCsvImporter
{
    public const MAX_ROWS = 5000;

    public const COLUMNS = [
        'nombre_comercial' => 'business_name',
        'tipo' => 'business_type',
        'contacto' => 'contact_name',
        'ciudad' => 'city',
        'provincia' => 'province',
        'pais' => 'country',
        'telefono' => 'phone',
        'email' => 'email',
        'web' => 'website',
        'url_fuente' => 'source_url',
        'categoria_interes' => 'interest_category',
        'notas' => 'notes',
    ];

    public function __construct(private LeadService $leads, private Auditor $auditor) {}

    public function import(string $path, string $filename, string $source, bool $dryRun): array
    {
        $rows = $this->read($path);
        if (count($rows) > self::MAX_ROWS) {
            throw new BusinessRuleException('El archivo supera '.self::MAX_ROWS.' filas. Divídalo en partes.', 'file');
        }

        DB::beginTransaction();
        try {
            $batch = LeadImport::create(['user_id' => Auth::id(), 'filename' => $filename, 'source' => $source, 'rows_total' => count($rows)]);
            $report = [];
            $counts = ['creado' => 0, 'duplicado' => 0, 'suprimido' => 0, 'error' => 0];
            foreach ($rows as $i => $row) {
                $line = $i + 2; // +1 encabezado, +1 base 1
                $result = $this->importRow($row, $source, $batch->id);
                $counts[$result['result']]++;
                $report[] = ['fila' => $line] + $result;
            }
            $batch->update([
                'created_count' => $counts['creado'],
                'duplicate_count' => $counts['duplicado'],
                'suppressed_count' => $counts['suprimido'],
                'error_count' => $counts['error'],
                'report' => $report,
            ]);
            if ($dryRun) {
                DB::rollBack();
            } else {
                $this->auditor->record('crm.importacion_csv', $batch, null, $counts);
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['dry_run' => $dryRun, 'import_id' => $dryRun ? null : $batch->id, 'rows' => count($rows), 'counts' => $counts, 'report' => $report];
    }

    private function importRow(array $row, string $source, int $batchId): array
    {
        $data = ['source' => $source, 'lead_import_id' => $batchId];
        foreach (self::COLUMNS as $col => $field) {
            if (isset($row[$col]) && trim($row[$col]) !== '') {
                $data[$field] = trim($row[$col]);
            }
        }
        $v = Validator::make($data, [
            'business_name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email'],
            'country' => ['nullable', 'string', 'size:2'],
            'source_url' => ['nullable', 'url'],
        ]);
        if ($v->fails()) {
            return ['result' => 'error', 'mensaje' => implode(' ', $v->errors()->all())];
        }
        $consent = strtolower(trim($row['consentimiento_whatsapp'] ?? ''));
        $method = trim($row['metodo_consentimiento'] ?? '');
        if (in_array($consent, ['si', 'sí', 'yes', '1'], true) && ! in_array($method, ConsentRecord::METHODS, true)) {
            return ['result' => 'error', 'mensaje' => 'Consentimiento sin método válido ('.implode(', ', ConsentRecord::METHODS).').'];
        }

        try {
            $created = DB::transaction(fn () => $this->leads->create($data));
        } catch (BusinessRuleException $e) {
            $result = $e->field === 'duplicate' ? 'duplicado' : ($e->field === 'phone' ? 'suprimido' : 'error');

            return ['result' => $result, 'mensaje' => $e->getMessage()];
        }
        $lead = $created['lead'];
        if (in_array($consent, ['si', 'sí', 'yes', '1'], true)) {
            $this->leads->recordConsent($lead, 'whatsapp', true, $method, trim($row['evidencia_consentimiento'] ?? '') ?: 'Importado de CSV');
        }
        $this->leads->activity($lead, 'importacion', 'Alta por CSV', null);

        return ['result' => 'creado', 'lead_id' => $lead->id, 'mensaje' => $created['possible_duplicates']->isNotEmpty() ? 'Posible duplicado por nombre: revisar' : null];
    }

    /** @return array<int, array<string, string>> */
    private function read(string $path): array
    {
        $content = file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', (string) $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        if (! $lines || $lines[0] === '') {
            throw new BusinessRuleException('El archivo está vacío.', 'file');
        }
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv($lines[0], $delimiter, '"', ''));
        if (! in_array('nombre_comercial', $header, true)) {
            throw new BusinessRuleException('Falta la columna obligatoria "nombre_comercial".', 'file');
        }
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter, '"', '');
            $rows[] = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
        }

        return $rows;
    }
}
