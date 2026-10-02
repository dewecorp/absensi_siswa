"""Render halaman PDF menjadi PNG untuk pratinjau Bank Soal.

Dipanggil dari PHP (ajax_generate_soal.php aksi=preview_gambar):
    py render_pdf_preview.py <file_pdf> <dir_output> <dpi> <maks_halaman>

Output: satu baris JSON ke stdout.
"""
import json
import os
import sys

try:
    import pymupdf
except ImportError:
    print(json.dumps({'ok': False, 'msg': 'PyMuPDF belum terinstal di server.'}))
    sys.exit(0)


def main():
    if len(sys.argv) < 5:
        print(json.dumps({'ok': False, 'msg': 'Argumen kurang.'}))
        return
    src = sys.argv[1]
    outdir = sys.argv[2]
    try:
        dpi = max(72, min(200, int(sys.argv[3])))
    except ValueError:
        dpi = 150
    try:
        maxp = max(1, min(20, int(sys.argv[4])))
    except ValueError:
        maxp = 15

    if not os.path.isfile(src):
        print(json.dumps({'ok': False, 'msg': 'Berkas PDF tidak ditemukan.'}))
        return

    try:
        doc = pymupdf.open(src)
    except Exception as e:  # noqa: BLE001 - teruskan pesan ke JSON
        print(json.dumps({'ok': False, 'msg': 'Gagal membuka PDF: %s' % e}))
        return

    total = len(doc)
    n = min(total, maxp)
    try:
        os.makedirs(outdir, exist_ok=True)
        # cegah listing direktori
        idx = os.path.join(outdir, 'index.html')
        if not os.path.isfile(idx):
            with open(idx, 'w', encoding='utf-8') as fh:
                fh.write('<!DOCTYPE html><title>403</title>')
        pages = []
        for i in range(n):
            fn = 'p%d.png' % (i + 1)
            pix = doc[i].get_pixmap(dpi=dpi)
            pix.save(os.path.join(outdir, fn))
            pages.append(fn)
    except Exception as e:  # noqa: BLE001
        print(json.dumps({'ok': False, 'msg': 'Gagal merender PDF: %s' % e}))
        return
    finally:
        try:
            doc.close()
        except Exception:  # noqa: BLE001
            pass

    print(json.dumps({'ok': True, 'pages': pages, 'total': total, 'shown': n}))


if __name__ == '__main__':
    main()
