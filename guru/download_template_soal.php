<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$format = $_GET['format'] ?? 'xlsx';

if ($format === 'xlsx' || $format === 'excel') {
    // Generate simple XML spreadsheet compatible with Microsoft Excel (.xls / .xml)
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Template_Impor_Soal_SIMAD.xls"');
    
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
       <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
      </Style>
      <Style ss:ID="CellCenter">
       <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
      </Style>
     </Styles>
     <Worksheet ss:Name="Template Soal">
      <Table>
       <Column ss:Width="110"/>
       <Column ss:Width="250"/>
       <Column ss:Width="100"/>
       <Column ss:Width="100"/>
       <Column ss:Width="100"/>
       <Column ss:Width="100"/>
       <Column ss:Width="100"/>
       <Column ss:Width="70"/>
       <Column ss:Width="60"/>
       <Row ss:Height="25">
        <Cell ss:StyleID="Header"><Data ss:Type="String">Tipe Soal</Data></Cell>
        <Cell ss:StyleID="Header"><Data ss:Type="String">Pertanyaan</Data></Cell>
        <Cell ss:StyleID="Header"><Data ss:Type="String">Pilihan A</Data></Cell>
        <Cell ss:StyleID="Header"><Data ss:Type="String">Pilihan B</Data></Cell>
        <Cell ss:StyleID="Header"><Data ss:Type="String">Pilihan C</Data></Cell>
        <Cell ss:StyleID="Header"><Data ss:Type="String">Pilihan D</Data></Cell>
        <Cell ss:StyleID="Header"><Data ss:Type="String">Pilihan E</Data></Cell>
        <Cell ss:StyleID="Header"><Data ss:Type="String">Kunci</Data></Cell>
        <Cell ss:StyleID="Header"><Data ss:Type="String">Bobot</Data></Cell>
       </Row>
       <Row>
        <Cell><Data ss:Type="String">Pilihan Ganda</Data></Cell>
        <Cell><Data ss:Type="String">Berapakah hasil dari 2 + 2?</Data></Cell>
        <Cell><Data ss:Type="String">3</Data></Cell>
        <Cell><Data ss:Type="String">4</Data></Cell>
        <Cell><Data ss:Type="String">5</Data></Cell>
        <Cell><Data ss:Type="String">6</Data></Cell>
        <Cell><Data ss:Type="String">7</Data></Cell>
        <Cell ss:StyleID="CellCenter"><Data ss:Type="String">B</Data></Cell>
        <Cell ss:StyleID="CellCenter"><Data ss:Type="Number">10</Data></Cell>
       </Row>
       <Row>
        <Cell><Data ss:Type="String">Essay</Data></Cell>
        <Cell><Data ss:Type="String">Jelaskan proses terjadinya siklus air!</Data></Cell>
        <Cell><Data ss:Type="String"></Data></Cell>
        <Cell><Data ss:Type="String"></Data></Cell>
        <Cell><Data ss:Type="String"></Data></Cell>
        <Cell><Data ss:Type="String"></Data></Cell>
        <Cell><Data ss:Type="String"></Data></Cell>
        <Cell ss:StyleID="CellCenter"><Data ss:Type="String"></Data></Cell>
        <Cell ss:StyleID="CellCenter"><Data ss:Type="Number">20</Data></Cell>
       </Row>
      </Table>
     </Worksheet>
    </Workbook>
    <?php
    exit;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="Panduan_Format_Soal_Word.txt"');
    echo "PANDUAN FORMAT PENULISAN SOAL DI MICROSOFT WORD (.docx)\n";
    echo "====================================================\n\n";
    echo "1. Tulis pertanyaan soal pada paragraf biasa.\n";
    echo "2. Di bawahnya, tulis pilihan jawaban diawali huruf A., B., C., D., E.\n";
    echo "3. Baris berikutnya tulis 'Kunci: [huruf pilihan benar]'.\n\n";
    echo "Contoh:\n";
    echo "Ibukota negara Indonesia adalah...\n";
    echo "A. Bandung\n";
    echo "B. Jakarta\n";
    echo "C. Surabaya\n";
    echo "D. Medan\n";
    echo "Kunci: B\n";
    exit;
}
