function exportToExcel() {
    // Create a container for the full report
    var container = document.createElement('div');
    
    // Add application name and school info
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/logo_1768301957.png" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3>' + (typeof schoolName !== 'undefined' ? schoolName : 'MI Sultan Fattah Sukosono') + '</h3>';
    headerDiv.innerHTML += '<h4>Data Guru - Tanggal ' + new Date().toLocaleDateString('id-ID') + '</h4></div><br style="clear: both;">';
    
    // Create a copy of the table to modify
    var table = document.getElementById('table-1');
    if (!table) {
        alert('Tabel tidak ditemukan');
        return;
    }
    var newTable = table.cloneNode(true);
    
    // Remove photo column (index 1) and action buttons column (last column)
    var rows = newTable.querySelectorAll('tr');
    rows.forEach(function(row) {
        var cells = row.querySelectorAll('td, th');
        if (cells.length > 0) {
            // Remove photo column (index 1)
            if (cells[1]) cells[1].remove();
            // Remove action buttons column (last column)
            if (cells[cells.length - 1]) cells[cells.length - 1].remove();
        }
    });
    
    // Update remaining image elements to show alt text
    var images = newTable.querySelectorAll('img');
    images.forEach(function(img) {
        var span = document.createElement('span');
        span.textContent = img.alt || '[Foto]';
        img.parentNode.replaceChild(span, img);
    });
    
    // Append header and table to container
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    var html = container.innerHTML;
    
    // Create download link
    var a = document.createElement('a');
    var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
    a.href = data;
    a.download = 'data_guru_' + new Date().toISOString().slice(0,10) + '.xls';
    a.click();
}

function exportToPDF() {
    // Print the table as PDF with F4 landscape format
    var printWindow = window.open('', '', 'height=860,width=1118'); // F4 dimensions
    printWindow.document.write('<html><head><title>Data Guru</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: landscape; margin: 1cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 9px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 4px; text-align: center; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 15px; }');
    printWindow.document.write('.logo { max-width: 80px; float: left; margin-right: 15px; }');
    printWindow.document.write('h2, h3, h4 { margin: 5px 0; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/logo_1768301957.png" alt="Logo" class="logo">');
    printWindow.document.write('<div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3>' + (typeof schoolName !== 'undefined' ? schoolName : 'MI Sultan Fattah Sukosono') + '</h3>');
    printWindow.document.write('<h4>Data Guru - Tanggal ' + new Date().toLocaleDateString('id-ID') + '</h4></div><br style="clear: both;">');
    
    // Get the table and remove photo and action columns
    var table = document.getElementById('table-1').cloneNode(true);
    if (table) {
        // Remove photo column (index 1) and action buttons column
        var rows = table.querySelectorAll('tr');
        rows.forEach(function(row) {
            var cells = row.querySelectorAll('td, th');
            if (cells.length > 0) {
                // Remove photo column (index 1)
                if (cells[1]) cells[1].remove();
                // Remove action buttons column (last column)
                if (cells[cells.length - 1]) cells[cells.length - 1].remove();
            }
        });
        
        // Update remaining images to show alt text
        var images = table.querySelectorAll('img');
        images.forEach(function(img) {
            var span = document.createElement('span');
            span.textContent = img.alt || '[Foto]';
            img.parentNode.replaceChild(span, img);
        });
        
        printWindow.document.write(table.outerHTML);
    }
    
    printWindow.document.write('</div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() {
        printWindow.print();
        printWindow.close();
    }, 500);
}