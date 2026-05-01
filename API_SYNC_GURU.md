# Kontrak API Sinkron Guru (SIMAD)

Dokumen ini menjelaskan endpoint sinkron data guru dari SIMAD ke aplikasi eksternal.

## Endpoint

- **URL**: `/api/v1/teachers.php`
- **Method**: `GET`
- **Content-Type**: `application/json`

## Autentikasi

Wajib kirim API key salah satu cara berikut:

- Query param: `api_key=...`
- Header: `X-API-KEY: ...`

Contoh:

- `GET /api/v1/teachers.php?api_key=SIS_CENTRAL_HUB_SECRET_2026`

Jika API key tidak valid atau tidak dikirim, respons:

- HTTP `401`
- Body:
```json
{
  "status": "error",
  "message": "Unauthorized: Invalid or missing API Key."
}
```

## Query Parameter

- `api_key` (string, wajib)
- `updated_since` (string, opsional) format **`Y-m-d H:i:s`**
- `limit` (integer, opsional) minimal 1, maksimal 1000

### Catatan `updated_since`

- Jika parameter `updated_since` dipakai, endpoint mencoba mode incremental.
- Mode incremental butuh kolom `updated_at` pada tabel `tb_guru`.
- Jika kolom belum ada, respons HTTP `400`.

## Mode Sinkron

- **Full sync**: tanpa `updated_since`
- **Incremental sync**: dengan `updated_since`

Field respons `sync_mode` akan berisi `full` atau `incremental`.

## Contoh Request

### 1) Full Sync

`GET /api/v1/teachers.php?api_key=SIS_CENTRAL_HUB_SECRET_2026`

### 2) Incremental Sync

`GET /api/v1/teachers.php?api_key=SIS_CENTRAL_HUB_SECRET_2026&updated_since=2026-04-29 00:00:00&limit=200`

## Contoh Respons Sukses (200)

```json
{
  "status": "success",
  "sync_mode": "full",
  "filter_updated_since": null,
  "total_data": 2,
  "last_sync": "2026-04-29 08:20:00",
  "data": [
    {
      "id_guru": "18",
      "nama_guru": "Abdul Ghofur, S.Pd.I",
      "kode_guru": "K",
      "nuptk": "2444764667200003",
      "tempat_lahir": "Jepara",
      "tanggal_lahir": "1986-11-12",
      "jenis_kelamin": "Laki-laki",
      "pendidikan": "S1",
      "wali_kelas": null,
      "mengajar": "[\"5\"]",
      "foto": null,
      "kelas_wali": "V",
      "mengajar_list": ["5"]
    }
  ]
}
```

## Definisi Field `data[]`

- `id_guru`: ID guru
- `nama_guru`: nama lengkap guru
- `kode_guru`: kode guru internal
- `nuptk`: NUPTK guru
- `tempat_lahir`: tempat lahir
- `tanggal_lahir`: tanggal lahir (`YYYY-mm-dd`)
- `jenis_kelamin`: `Laki-laki` / `Perempuan`
- `pendidikan`: jenjang formal (`SLTA`, `D1`, `D2`, `D3`, `S1`, `S2`, `S3`) atau `null` jika belum diisi
- `wali_kelas`: nilai dari kolom `tb_guru.wali_kelas`
- `mengajar`: JSON string asli dari database
- `mengajar_list`: hasil decode `mengajar` menjadi array (atau `null`)
- `foto`: nama file foto guru (jika ada)
- `kelas_wali`: daftar kelas yang diwalikan (hasil join), dipisah koma

## Kode Error

- `401`: API key salah/tidak ada
- `400`: parameter tidak valid (`updated_since`) atau incremental belum didukung
- `500`: error database internal

## Rekomendasi Integrasi Aplikasi Tujuan

- Simpan `last_sync` terakhir dari respons sukses.
- Untuk incremental berikutnya, kirim `updated_since` dari waktu sinkron terakhir.
- Jika dapat `400` karena `updated_at` belum ada, fallback ke full sync.
- Gunakan `limit` untuk batch jika data besar.
