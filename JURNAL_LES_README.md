# Jurnal Les Feature - Implementation Summary

## Database Setup
Run this file first to create the required table:
- **File**: `admin/setup_jurnal_les.php`
- **Action**: Open in browser: `http://localhost/absen_siswa/admin/setup_jurnal_les.php`
- **Table Created**: `tb_jurnal_les`

## Files Created

### 1. Guru/Wali Jurnal Les (CRUD Operations)
- **File**: `guru/jurnal_les.php`
- **Copied to**: `wali/jurnal_les.php`
- **Access**: Only for Grade 6 teachers (guru kelas 6) and Wali Kelas 6
- **Features**:
  - Add/Edit journal entries (auto-update if same date/class/waktu exists)
  - Delete journal entries
  - Filter by class
  - Select waktu from jadwal les options (Pagi, Siang, Sore)
  - DataTables with Indonesian localization
  - SweetAlert2 confirmations

### 2. Admin Jurnal Les (Delete Only)
- **File**: `admin/jurnal_les.php`
- **Access**: Admin and Kepala Madrasah
- **Features**:
  - Delete single journal entry
  - Delete multiple journal entries (bulk delete)
  - Filter by class (Grade 6 only)
  - Filter by teacher
  - Filter by waktu (Pagi, Siang, Sore)
  - DataTables with Indonesian localization
  - SweetAlert2 confirmations

## Menu Structure Updates

### Admin Sidebar
```
Jurnal (Parent Menu)
├── Jurnal Mengajar (existing)
└── Jurnal Les (new)
```

### Guru Sidebar
```
Jurnal (Parent Menu)
├── Jurnal Mengajar (existing)
└── Jurnal Les (new - only for grade 6 teachers)
```

### Wali Sidebar
```
Jurnal (Parent Menu)
├── Jurnal Mengajar (existing)
└── Jurnal Les (new - only for grade 6 wali)
```

## Database Structure

### Table: `tb_jurnal_les`
```sql
- id (INT, PRIMARY KEY, AUTO_INCREMENT)
- id_kelas (INT, FOREIGN KEY -> tb_kelas)
- id_guru (INT, FOREIGN KEY -> tb_guru)
- waktu (VARCHAR(50)) - From jadwal les (Pagi/Siang/Sore)
- mapel (VARCHAR(100))
- materi (TEXT)
- tanggal (DATE)
- created_at (TIMESTAMP)
```

**Note**: No `jam_ke` or `jenis` columns (unlike jurnal mengajar)

## Key Differences from Jurnal Mengajar

| Aspect | Jurnal Mengajar | Jurnal Les |
|--------|----------------|------------|
| Time Selection | Jam Ke (1,2,3...) + Jenis (Reguler/Ramadhan) | Waktu (Pagi/Siang/Sore) only |
| Access | All teachers | Only Grade 6 teachers & wali |
| Admin Actions | View, Delete | Delete only |
| CRUD Location | Guru/Wali | Guru/Wali (Grade 6 only) |
| Table Columns | jam_ke, jenis | waktu (no jam_ke, no jenis) |

## Usage Flow

### For Teachers (Guru/Wali - Grade 6 Only):
1. Navigate to **Jurnal → Jurnal Les**
2. Select Class (only Grade 6 classes shown)
3. Fill form:
   - Date (default: today)
   - Waktu (from dropdown: Pagi/Siang/Sore - based on jadwal les)
   - Mata Pelajaran
   - Materi
4. Click **Simpan Jurnal Les**
   - If entry exists for same date/class/waktu → Update
   - If new → Insert
5. View entries in table below
6. Delete entries using trash icon

### For Admin:
1. Navigate to **Jurnal → Jurnal Les**
2. Filter by:
   - Class (Grade 6 only)
   - Teacher
   - Waktu (Pagi/Siang/Sore)
3. Delete single entries or select multiple and click **Hapus Dipilih**

## Authorization
- **Create/Edit/Delete**: Guru/Wali with Grade 6 classes only
- **Delete Only (Admin)**: Admin, Kepala Madrasah
- **View**: Based on role permissions

## Dependencies
- DataTables 1.10.25
- SweetAlert2 v11
- jQuery
- Bootstrap 4

## Testing Checklist
- [ ] Run setup_jurnal_les.php to create table
- [ ] Login as Grade 6 teacher → access Jurnal Les
- [ ] Create journal entry
- [ ] Edit journal entry (same date/waktu)
- [ ] Delete journal entry
- [ ] Login as Admin → verify delete-only access
- [ ] Verify menu appears only for Grade 6 teachers
- [ ] Verify waktu options come from tb_jadwal_les
