"""Genera los PDF de docs/legal/*.md en docs/legal/pdf/.

Uso (desde la raíz del repositorio):
    uv run --with markdown --with pymupdf python docs/legal/build_pdfs.py

El Markdown es la fuente editable; este script no modifica los .md. Cada PDF lleva en el pie el nombre del documento,
la versión (la línea «Versión x.y» del propio documento) y el número de página, y un aviso de borrador.
"""
import re
from pathlib import Path

import fitz
import markdown

ROOT = Path(__file__).parent
OUT = ROOT / 'pdf'
OUT.mkdir(exist_ok=True)

CSS = """
body { font-family: sans-serif; font-size: 10pt; line-height: 1.35; }
h1 { font-size: 18pt; margin: 0 0 8pt 0; }
h2 { font-size: 13pt; margin: 14pt 0 4pt 0; }
h3 { font-size: 11pt; margin: 10pt 0 3pt 0; }
p { margin: 0 0 6pt 0; }
li { margin: 0 0 2pt 0; }
table { border-collapse: collapse; width: 100%; margin: 4pt 0 8pt 0; }
th, td { border: 0.5pt solid #888; padding: 3pt; font-size: 8.5pt; vertical-align: top; }
th { background-color: #e8e8e8; }
blockquote { margin: 4pt 0 6pt 14pt; padding-left: 6pt; border-left: 2pt solid #888; }
code { font-family: monospace; font-size: 9pt; }
"""

SUBSTITUTIONS = {'☐': '[  ]', '≤': '<=', '→': '->', '…': '...', '•': '-', '‑': '-'}

MARGIN = 54
WIDE = ('14-', 'datos-')  # documentos con tablas anchas: página horizontal


def page_for(path):
    return fitz.paper_rect('a4-l' if path.name.startswith(WIDE) else 'a4')


def build(path: Path) -> None:
    text = path.read_text(encoding='utf-8')
    for old, new in SUBSTITUTIONS.items():
        text = text.replace(old, new)
    PAGE = page_for(path)
    WHERE = fitz.Rect(MARGIN, MARGIN, PAGE.width - MARGIN, PAGE.height - MARGIN - 14)
    title = re.search(r'^# (.+)$', text, re.M).group(1)
    version = (re.search(r'Versión\s+([0-9.]+)', text) or [None, '?'])[1]
    html = markdown.markdown(text, extensions=['tables', 'sane_lists'])
    story = fitz.Story(html=html, user_css=CSS + ('th, td { font-size: 7.5pt; }' if path.name.startswith(WIDE) else ''))
    target = OUT / (path.stem + '.pdf')
    writer = fitz.DocumentWriter(str(target))
    pages = []
    more = True
    while more:
        device = writer.begin_page(PAGE)
        more, _ = story.place(WHERE)
        story.draw(device)
        writer.end_page()
        pages.append(1)
    writer.close()

    doc = fitz.open(target)
    total = len(doc)
    for index, page in enumerate(doc, start=1):
        footer = f'{title[:70]}  |  v{version} (BORRADOR, no aprobado)  |  Página {index} de {total}'
        page.insert_text((MARGIN, PAGE.height - 30), footer, fontsize=7.5, color=(0.35, 0.35, 0.35))
    doc.set_metadata({'title': title, 'subject': f'Versión {version} - borrador para revisión jurídica', 'author': 'CCG - Ava Platform'})
    doc.saveIncr()
    doc.close()
    print(f'{target.relative_to(ROOT.parent.parent)}  ({total} páginas)')


for md in [*sorted(ROOT.glob('[0-9][0-9]-*.md')), ROOT / 'datos-empresa-por-completar.md']:
    build(md)
