<?php
// Import modal template
if (!isset($_SESSION)) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$import_type = isset($_GET['type']) ? $_GET['type'] : '';
?>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel">Import Data from Excel</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="importForm" method="POST" action="import_handler.php" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="import_type" id="importType" value="<?php echo $import_type; ?>">
                    <input type="hidden" name="import_data" value="1">
                    
                    <div class="form-group">
                        <label for="excel_file">Pilih File Excel</label>
                        <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx,.xls" required>
                        <small class="form-text text-muted">
                            Format yang didukung: XLS, XLSX<br>
                            Ukuran maksimal file: 5MB<br>
                            <?php if($import_type == 'guru'): ?>
                                Kolom yang dibutuhkan: Nama Guru, NUPTK, Tempat Lahir, Tanggal Lahir, Jenis Kelamin, Password<br>
                                Catatan: Kolom Wali Kelas telah dihapus karena diatur di Data Kelas
                            <?php elseif($import_type == 'siswa'): ?>
                                Kolom yang dibutuhkan: Nama Siswa, NISN, Jenis Kelamin, Kelas ID
                            <?php endif; ?>
                        </small>
                    </div>
                    
                    
                    
                    <div id="importProgress" class="progress mb-3" style="display: none;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                    </div>
                    
                    <div id="importResult" class="mt-3" style="display: none;"></div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <a href="#" class="btn btn-info" id="downloadTemplateLink" download>Unduh Template Excel</a>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success" id="importSubmitBtn" style="display: none;">Impor Data</button>
                </div>
            </form>
        </div>
    </div>
</div>