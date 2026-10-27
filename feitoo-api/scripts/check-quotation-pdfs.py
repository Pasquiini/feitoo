"""Offline inspection of synthetic demo PDFs; PyMuPDF is a local validation tool only.

Usage: python scripts/check-quotation-pdfs.py <private-run-directory> [--previews]
Optional private install: storage/app/private/quotation-validation/python
"""

import hashlib
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "storage/app/private/quotation-validation/python"))
import pymupdf  # noqa: E402


def compact(text):
    return re.sub(r"\s+", "", text)


run = Path(sys.argv[1]).resolve()
private_root = (ROOT / "storage/app/private/quotation-validation/runs").resolve()
if not run.is_relative_to(private_root):
    raise SystemExit("Only the private synthetic demonstration directory is allowed.")
manifest = json.loads((run / "manifest.json").read_text(encoding="utf-8"))
assert manifest["synthetic_only"] is True
results = []
for entry in manifest["files"]:
    path = (run / entry["file"]).resolve()
    assert path.parent == run and path.suffix == ".pdf"
    assert hashlib.sha256(path.read_bytes()).hexdigest() == entry["sha256"]
    doc = pymupdf.open(path)
    assert not doc.is_repaired and not doc.is_encrypted, entry["file"]
    assert len(doc) == entry["pages"]
    text = ""
    body = ""
    overflow = []
    totals_page_with_last_item = False
    for index, page in enumerate(doc):
        assert abs(page.rect.width - 595.28) < 1 and abs(page.rect.height - 841.89) < 1
        page_text = page.get_text()
        page_body = ""
        assert len(page_text.strip()) > 40, f"Blank page: {entry['file']} {index + 1}"
        assert f"Página {index + 1} de {len(doc)}" in page_text
        text += page_text
        for block in page.get_text("dict")["blocks"]:
            for line in block.get("lines", []):
                for span in line["spans"]:
                    if abs(span["size"] - 9.5) < 0.1:
                        body += span["text"]
                        page_body += span["text"]
                    x0, y0, x1, y1 = span["bbox"]
                    if x0 < 35 or x1 > 561 or y0 < 9 or y1 > 830:
                        overflow.append({"page": index + 1, "bbox": [x0, y0, x1, y1]})
                    if y0 < 790 < y1 or (790 <= y0 < 805):
                        overflow.append({"page": index + 1, "footer_overlap": [x0, y0, x1, y1]})
        if "Subtotal" in page_text and compact(entry["last_item_tail"]) in compact(page_body):
            totals_page_with_last_item = True
    missing = [value for value in entry["expected_text"] if compact(value) not in compact(text) and compact(value) not in compact(body)]
    assert not missing, f"Missing or truncated content: {entry['file']}: {missing[:1]}"
    assert not overflow, f"Out of content bounds: {entry['file']}: {overflow[:3]}"
    assert totals_page_with_last_item, f"Totals orphaned from last item: {entry['file']}"
    if entry["scenario"] in ["transparent-png", "jpeg-logo"]:
        assert sum(len(page.get_images()) for page in doc) >= 1
    (run / (path.stem + ".txt")).write_text(text, encoding="utf-8")
    if "--previews" in sys.argv and entry["scenario"] in ["simple", "services", "long-text", "hundred-items", "transparent-png", "free-and-high"]:
        preview_dir = run / "previews"
        preview_dir.mkdir(exist_ok=True)
        for index in sorted({0, len(doc) - 1}):
            doc[index].get_pixmap(matrix=pymupdf.Matrix(1.35, 1.35)).save(preview_dir / f"{path.stem}-p{index + 1}.png")
    results.append({"file": entry["file"], "pages": len(doc), "text_complete": True, "bounds_ok": True, "integrity_ok": True})
    doc.close()
(run / "inspection.json").write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding="utf-8")
print(json.dumps({"verified_pdfs": len(results), "pages": sum(row["pages"] for row in results), "inspection": str(run / "inspection.json")}, ensure_ascii=False))
