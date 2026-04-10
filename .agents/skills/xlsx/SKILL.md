---
name: xlsx
description: Generación y lectura de archivos Excel (XLSX) desde PHP o JavaScript. Usa cuando necesites exportar datos a Excel, leer hojas de cálculo, formatear celdas, aplicar estilos, o trabajar con múltiples hojas.
---

# XLSX Skill

## PHP — PhpSpreadsheet

### Instalación
```bash
composer require phpoffice/phpspreadsheet
```

### Exportar datos a Excel
```php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

function exportarExcel(string $titulo, array $headers, array $filas): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($titulo);

    // Encabezados con estilo
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getStyle($col . '1')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F3A5F']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);
        $col++;
    }

    // Datos
    foreach ($filas as $rowIndex => $fila) {
        $col = 'A';
        foreach ($fila as $valor) {
            $sheet->setCellValue($col . ($rowIndex + 2), $valor);
            $col++;
        }
    }

    // Auto-ajustar columnas
    foreach (range('A', $col) as $c) {
        $sheet->getColumnDimension($c)->setAutoSize(true);
    }

    // Enviar al navegador
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $titulo . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
```

### Uso típico
```php
// En cwl.php o guerras.php — botón "Exportar Excel"
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    require 'vendor/autoload.php';

    $headers = ['Jugador', 'Estrellas', 'Destrucción %', 'Ataques'];
    $filas = [];
    foreach ($jugadores as $j) {
        $filas[] = [$j['nombre'], $j['estrellas'], $j['porcentaje'], $j['ataques']];
    }
    exportarExcel('CWL_' . $temporada['mes'], $headers, $filas);
}
```

## JavaScript — SheetJS (cliente)

### CDN
```html
<script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
```

### Exportar tabla HTML a Excel
```javascript
function exportTableToExcel(tableId, filename = 'export') {
    const table = document.getElementById(tableId);
    const wb = XLSX.utils.table_to_book(table, { sheet: 'Datos' });
    XLSX.writeFile(wb, filename + '.xlsx');
}
```

### Exportar array de objetos
```javascript
function exportDataToExcel(data, filename = 'export', sheetName = 'Hoja1') {
    const ws = XLSX.utils.json_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, sheetName);

    // Anchos de columna automáticos
    const cols = Object.keys(data[0]).map(key => ({ wch: Math.max(key.length, 12) }));
    ws['!cols'] = cols;

    XLSX.writeFile(wb, filename + '.xlsx');
}
```

## Botón de Exportar (UI Integrada)

```html
<button onclick="exportTableToExcel('miTabla', 'reporte')" class="btn btn-success btn-sm">
    <i class="bi bi-file-earmark-excel"></i> Exportar Excel
</button>
```

## Patrones Comunes

### Exportar con múltiples hojas
```php
$spreadsheet->createSheet()->setTitle('Hoja 2');
$spreadsheet->setActiveSheetIndex(1);
// ... llenar hoja 2
```

### Leer un Excel subido
```php
$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($_FILES['archivo']['tmp_name']);
$spreadsheet = $reader->load($_FILES['archivo']['tmp_name']);
$data = $spreadsheet->getActiveSheet()->toArray();
```
