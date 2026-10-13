<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$format = strtolower(trim((string)($_GET['format'] ?? 'excel')));
$mode = strtolower(trim((string)($_GET['mode'] ?? 'cbt')));
$is_kuis = ($mode === 'kuis');
$label = $is_kuis ? 'Kuis' : 'CBT';

function xls_cell($v, $style = '', $type = null) {
    if ($type === null) {
        $type = (is_numeric($v) && $v !== '') ? 'Number' : 'String';
    }
    $v = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $st = $style !== '' ? ' ss:StyleID="' . $style . '"' : '';
    return "<Cell{$st}><Data ss:Type=\"{$type}\">{$v}</Data></Cell>";
}

if (in_array($format, ['excel', 'xlsx', 'xls'], true)) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Template_Soal_' . $label . '_SIMAD.xls"');

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    ?>
    <Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
              xmlns:o="urn:schemas-microsoft-com:office:office"
              xmlns:x="urn:schemas-microsoft-com:office:excel"
              xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
              xmlns:html="http://www.w3.org/TR/REC-html40">
     <Styles>
      <Style ss:ID="Header">
       <Font ss:Bold="1" ss:Color="#FFFFFF"/>
       <Interior ss:Color="#1E40AF" ss:Pattern="Solid"/>
       <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      </Style>
      <Style ss:ID="CellCenter">
       <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
      </Style>
      <Style ss:ID="Note">
       <Font ss:Italic="1" ss:Color="#475569"/>
      </Style>
     </Styles>

     <!-- LEMBAR 1: PILIHAN GANDA (biasa + kompleks) -->
     <Worksheet ss:Name="Pilihan_Ganda">
      <Table>
       <Column ss:Width="40"/>
       <Column ss:Width="260"/>
       <Column ss:Width="110"/>
       <Column ss:Width="110"/>
       <Column ss:Width="110"/>
       <Column ss:Width="110"/>
       <Column ss:Width="110"/>
       <Column ss:Width="120"/>
       <Row ss:Height="32">
        <?= xls_cell('No', 'Header') ?>
        <?= xls_cell('Pertanyaan', 'Header') ?>
        <?= xls_cell('Pilihan A', 'Header') ?>
        <?= xls_cell('Pilihan B', 'Header') ?>
        <?= xls_cell('Pilihan C', 'Header') ?>
        <?= xls_cell('Pilihan D', 'Header') ?>
        <?= xls_cell('Pilihan E (boleh kosong)', 'Header') ?>
        <?= xls_cell('Kunci (satu huruf; kompleks pisahkan koma: A,C)', 'Header') ?>
       </Row>
       <Row>
        <?= xls_cell(1, 'CellCenter', 'Number') ?>
        <?= xls_cell('Ibukota negara Indonesia adalah...') ?>
        <?= xls_cell('Bandung') ?>
        <?= xls_cell('Jakarta') ?>
        <?= xls_cell('Surabaya') ?>
        <?= xls_cell('Medan') ?>
        <?= xls_cell('') ?>
        <?= xls_cell('B', 'CellCenter') ?>
       </Row>
       <Row>
        <?= xls_cell(2, 'CellCenter', 'Number') ?>
        <?= xls_cell('Berikut yang termasuk alat pencernaan untuk mengunyah makanan... (pilih semua yang benar)') ?>
        <?= xls_cell('Mulut') ?>
        <?= xls_cell('Kerongkongan') ?>
        <?= xls_cell('Gigi') ?>
        <?= xls_cell('Lambung') ?>
        <?= xls_cell('') ?>
        <?= xls_cell('A,C', 'CellCenter') ?>
       </Row>
       <Row>
        <?= xls_cell('', 'Note') ?>
        <?= xls_cell('Hapus 2 contoh di atas, isi soal Anda mulai baris 3. Bobot diatur di halaman Kelola Soal.', 'Note') ?>
        <?= xls_cell('', 'Note') ?>
        <?= xls_cell('', 'Note') ?>
        <?= xls_cell('', 'Note') ?>
        <?= xls_cell('', 'Note') ?>
        <?= xls_cell('', 'Note') ?>
        <?= xls_cell('', 'Note') ?>
       </Row>
      </Table>
     </Worksheet>

     <?php if (!$is_kuis): ?>
     <!-- LEMBAR 2: URAIAN (khusus CBT) -->
     <Worksheet ss:Name="Uraian">
      <Table>
       <Column ss:Width="40"/>
       <Column ss:Width="300"/>
       <Column ss:Width="300"/>
       <Row ss:Height="30">
        <?= xls_cell('No', 'Header') ?>
        <?= xls_cell('Pertanyaan Uraian', 'Header') ?>
        <?= xls_cell('Kunci Jawaban / Kata Kunci', 'Header') ?>
       </Row>
       <Row>
        <?= xls_cell(1, 'CellCenter', 'Number') ?>
        <?= xls_cell('Jelaskan proses terjadinya siklus air!') ?>
        <?= xls_cell('evaporasi, kondensasi, presipitasi, infiltrasi') ?>
       </Row>
       <Row>
        <?= xls_cell(2, 'CellCenter', 'Number') ?>
        <?= xls_cell('Sebutkan 3 fungsi darah dalam tubuh manusia!') ?>
        <?= xls_cell('mengangkut oksigen, mengangkut sari makanan, melawan penyakit') ?>
       </Row>
       <Row>
        <?= xls_cell('', 'Note') ?>
        <?= xls_cell('Hapus 2 contoh di atas, isi soal uraian Anda mulai baris 3.', 'Note') ?>
        <?= xls_cell('', 'Note') ?>
       </Row>
      </Table>
     </Worksheet>
     <?php endif; ?>
    </Workbook>
    <?php
    exit;
}

// ---- Format Word (.docx asli, bukan teks) ----
if (in_array($format, ['docx', 'word'], true)) {
    $tmp = tempnam(sys_get_temp_dir(), 'tpl_soal_') . '.docx';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $core = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>Template Soal ' . $label . ' SIMAD</dc:title>'
        . '</cp:coreProperties>';

    $app = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>SIMAD</Application></Properties>';

    $para = function ($text, $bold = false, $size = 22) {
        $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $b = $bold ? '<w:b/>' : '';
        return "<w:p><w:pPr><w:spacing w:after=\"120\"/></w:pPr><w:r><w:rPr>{$b}<w:sz w:val=\"{$size}\"/><w:szCs w:val=\"{$size}\"/></w:rPr><w:t xml:space=\"preserve\">{$t}</w:t></w:r></w:p>";
    };

    $body = '';
    $body .= $para('TEMPLATE SOAL ' . strtoupper($label) . ' SIMAD', true, 28);
    $body .= $para('Bagian A — Pilihan Ganda (satu kunci maupun kompleks)', true);
    $body .= $para('Soal 1: Ibukota negara Indonesia adalah...');
    $body .= $para('A. Bandung');
    $body .= $para('B. Jakarta');
    $body .= $para('C. Surabaya');
    $body .= $para('D. Medan');
    $body .= $para('Kunci: B');
    $body .= $para('');
    $body .= $para('Soal 2: Berikut yang termasuk alat pencernaan untuk mengunyah makanan... (pilih semua yang benar)');
    $body .= $para('A. Mulut');
    $body .= $para('B. Kerongkongan');
    $body .= $para('C. Gigi');
    $body .= $para('D. Lambung');
    $body .= $para('Kunci: A,C');
    $body .= $para('');
    if (!$is_kuis) {
        $body .= $para('Bagian B — Uraian', true);
        $body .= $para('Soal 3: Jelaskan proses terjadinya siklus air!');
        $body .= $para('Kunci Uraian: evaporasi, kondensasi, presipitasi, infiltrasi');
        $body .= $para('');
        $body .= $para('Soal 4: Sebutkan 3 fungsi darah dalam tubuh manusia!');
        $body .= $para('Kunci Uraian: mengangkut oksigen, mengangkut sari makanan, melawan penyakit');
        $body .= $para('');
    }
    $body .= $para('Catatan: hapus contoh di atas, tulis soal Anda dengan pola yang sama. Bobot per jenis soal diatur di halaman Kelola Soal.', false, 20);

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
        . '</w:body></w:document>';

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('docProps/core.xml', $core);
        $zip->addFromString('docProps/app.xml', $app);
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="Template_Soal_' . $label . '_SIMAD.docx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// fallback: panduan teks
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="Panduan_Format_Soal_Word.txt"');
echo "PANDUAN FORMAT PENULISAN SOAL DI MICROSOFT WORD (.docx)\n";
echo "====================================================\n\n";
echo "Bagian Pilihan Ganda:\n";
echo "Soal 1: ...\nA. ...\nB. ...\nKunci: B (kompleks contoh: A,C)\n\n";
if (!$is_kuis) {
    echo "Bagian Uraian:\n";
    echo "Soal 3: ...\nKunci Uraian: kata kunci jawaban\n";
}
exit;
