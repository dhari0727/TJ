"""
JourneyAI master guide: shared look and building blocks (fonts, styles, flowables, diagram primitives).
Used by build_master_guide.py and guide_content.py. Needs reportlab only.
"""
import os
from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.lib.utils import simpleSplit
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (Flowable, KeepTogether, Paragraph, Spacer, Table, TableStyle, PageBreak)

# ------------------------------------------------------------------ brand
DEEP = colors.HexColor("#123524")
GREEN = colors.HexColor("#1E7B4F")
MELON = colors.HexColor("#E63457")
CREAM = colors.HexColor("#FFF6EE")
SAND = colors.HexColor("#FFE9DC")
INK = colors.HexColor("#1B2A21")
DIM = colors.HexColor("#3F5547")
MUTE = colors.HexColor("#6B7F72")
RULE = colors.HexColor("#D9CFC3")
MINT = colors.HexColor("#E4F1E8")
LIME = colors.HexColor("#B6E36B")
AMBER = colors.HexColor("#B86E00")

FONT_DIR = os.environ.get("WINDIR", r"C:\Windows") + r"\Fonts"


def _reg(name, file, fallback):
    p = os.path.join(FONT_DIR, file)
    if os.path.exists(p):
        pdfmetrics.registerFont(TTFont(name, p))
        return name
    return fallback


SERIF = _reg("Serif", "georgia.ttf", "Times-Roman")
SERIF_B = _reg("Serif-B", "georgiab.ttf", "Times-Bold")
SERIF_I = _reg("Serif-I", "georgiai.ttf", "Times-Italic")
SANS = _reg("Sans", "segoeui.ttf", "Helvetica")
SANS_B = _reg("Sans-B", "segoeuib.ttf", "Helvetica-Bold")
SANS_I = _reg("Sans-I", "segoeuii.ttf", "Helvetica-Oblique")
MONO = _reg("Mono", "consola.ttf", "Courier")
MONO_B = _reg("Mono-B", "consolab.ttf", "Courier-Bold")
for fam, (n, b, i) in {"Sans": (SANS, SANS_B, SANS_I), "Serif": (SERIF, SERIF_B, SERIF_I)}.items():
    pdfmetrics.registerFontFamily(fam, normal=n, bold=b, italic=i, boldItalic=b)

PAGE_W, PAGE_H = 210 * mm, 297 * mm
LM, RM, TM, BM = 22 * mm, 20 * mm, 24 * mm, 22 * mm
CW = PAGE_W - LM - RM   # content width

# ------------------------------------------------------------------ paragraph styles
ST = {
    "body": ParagraphStyle("body", fontName=SANS, fontSize=9.6, leading=14.2, textColor=INK, spaceAfter=5.5, alignment=TA_LEFT),
    "small": ParagraphStyle("small", fontName=SANS, fontSize=8.2, leading=11.4, textColor=DIM, spaceAfter=3),
    "lead": ParagraphStyle("lead", fontName=SERIF_I, fontSize=12, leading=17.5, textColor=DIM, spaceAfter=10),
    "h1": ParagraphStyle("h1", fontName=SERIF_B, fontSize=25, leading=29, textColor=DEEP, spaceAfter=4),
    "h2": ParagraphStyle("h2", fontName=SERIF_B, fontSize=14, leading=18, textColor=DEEP, spaceBefore=11, spaceAfter=4, keepWithNext=1),
    "h3": ParagraphStyle("h3", fontName=SANS_B, fontSize=10.2, leading=14, textColor=GREEN, spaceBefore=7, spaceAfter=2, keepWithNext=1),
    "bullet": ParagraphStyle("bullet", fontName=SANS, fontSize=9.6, leading=13.8, textColor=INK, leftIndent=12, bulletIndent=2, spaceAfter=2.5),
    "cell": ParagraphStyle("cell", fontName=SANS, fontSize=8.4, leading=11.3, textColor=INK),
    "cellb": ParagraphStyle("cellb", fontName=SANS_B, fontSize=8.4, leading=11.3, textColor=colors.white),
    "cellm": ParagraphStyle("cellm", fontName=MONO, fontSize=7.8, leading=10.6, textColor=INK),
    "code": ParagraphStyle("code", fontName=MONO, fontSize=8.4, leading=12, textColor=DEEP),
    "note": ParagraphStyle("note", fontName=SANS, fontSize=9, leading=13, textColor=INK),
    "cap": ParagraphStyle("cap", fontName=SANS_I, fontSize=8, leading=10.5, textColor=MUTE, spaceAfter=9),
    "toc1": ParagraphStyle("toc1", fontName=SANS_B, fontSize=10.5, leading=17, textColor=DEEP, leftIndent=0),
    "toc2": ParagraphStyle("toc2", fontName=SANS, fontSize=9, leading=13.5, textColor=DIM, leftIndent=14),
}


def esc(s):
    return str(s).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def P(text, style="body"):
    return Paragraph(text, ST[style])


def bullets(items, style="bullet"):
    return [Paragraph(t, ST[style], bulletText="\u2022") for t in items]


def steps(items):
    """Numbered pseudo-code style steps, kept as one block."""
    rows = []
    for i, t in enumerate(items, 1):
        rows.append([Paragraph(f"<font name='{SANS_B}' color='#E63457'>{i}</font>", ST["cell"]), Paragraph(t, ST["cell"])])
    t = Table(rows, colWidths=[8 * mm, CW - 8 * mm])
    t.setStyle(TableStyle([("VALIGN", (0, 0), (-1, -1), "TOP"), ("TOPPADDING", (0, 0), (-1, -1), 2.2), ("BOTTOMPADDING", (0, 0), (-1, -1), 2.2),
                           ("LEFTPADDING", (0, 0), (-1, -1), 2), ("LINEBELOW", (0, 0), (-1, -2), 0.3, RULE)]))
    return t


def formula(lines, title=None):
    """Formula block: cream panel, monospaced, green left rule."""
    body = "<br/>".join(esc(l).replace(" ", "&nbsp;") if l.startswith(" ") else esc(l) for l in lines)
    cells = []
    if title:
        cells.append([Paragraph(f"<font name='{SANS_B}' size='7.5' color='#1E7B4F'>{esc(title).upper()}</font>", ST["small"])])
    cells.append([Paragraph(body, ST["code"])])
    t = Table(cells, colWidths=[CW])
    t.setStyle(TableStyle([("BACKGROUND", (0, 0), (-1, -1), SAND), ("LINEBEFORE", (0, 0), (0, -1), 3, GREEN),
                           ("LEFTPADDING", (0, 0), (-1, -1), 10), ("RIGHTPADDING", (0, 0), (-1, -1), 8),
                           ("TOPPADDING", (0, 0), (-1, -1), 4), ("BOTTOMPADDING", (0, 0), (-1, -1), 5)]))
    return KeepTogether([t, Spacer(1, 6)])


def callout(text, kind="note", title=None):
    """kind: note (green), caveat (watermelon), measured (deep)."""
    col = {"note": GREEN, "caveat": MELON, "measured": DEEP}[kind]
    bg = {"note": MINT, "caveat": colors.HexColor("#FDE8EC"), "measured": colors.HexColor("#E8EFEA")}[kind]
    label = title or {"note": "NOTE", "caveat": "CAVEAT", "measured": "MEASURED"}[kind]
    inner = [Paragraph(f"<font name='{SANS_B}' size='7.5' color='{col.hexval().replace('0x', '#')}'>{esc(label).upper()}</font>", ST["small"]),
             Paragraph(text, ST["note"])]
    t = Table([[inner]], colWidths=[CW])
    t.setStyle(TableStyle([("BACKGROUND", (0, 0), (-1, -1), bg), ("LINEBEFORE", (0, 0), (0, -1), 3, col),
                           ("LEFTPADDING", (0, 0), (-1, -1), 10), ("RIGHTPADDING", (0, 0), (-1, -1), 10),
                           ("TOPPADDING", (0, 0), (-1, -1), 6), ("BOTTOMPADDING", (0, 0), (-1, -1), 6)]))
    return KeepTogether([t, Spacer(1, 7)])


def table(header, rows, widths, mono_cols=(), zebra=True, size=None):
    """Brand table. widths are fractions of the content width. Cell text may hold inline markup."""
    wsum = float(sum(widths))
    cw = [CW * w / wsum for w in widths]
    data = [[Paragraph(esc(h), ST["cellb"]) for h in header]]
    for r in rows:
        data.append([Paragraph(str(c), ST["cellm"] if i in mono_cols else ST["cell"]) for i, c in enumerate(r)])
    t = Table(data, colWidths=cw, repeatRows=1)
    sty = [("BACKGROUND", (0, 0), (-1, 0), DEEP), ("VALIGN", (0, 0), (-1, -1), "TOP"),
           ("TOPPADDING", (0, 0), (-1, -1), 3.2), ("BOTTOMPADDING", (0, 0), (-1, -1), 3.4),
           ("LEFTPADDING", (0, 0), (-1, -1), 5), ("RIGHTPADDING", (0, 0), (-1, -1), 5),
           ("LINEBELOW", (0, 1), (-1, -1), 0.3, RULE), ("LINEBELOW", (0, -1), (-1, -1), 0.8, DEEP)]
    if zebra:
        for i in range(2, len(data), 2):
            sty.append(("BACKGROUND", (0, i), (-1, i), colors.HexColor("#FBF1E8")))
    t.setStyle(TableStyle(sty))
    return [t, Spacer(1, 8)]


# ------------------------------------------------------------------ section heading with TOC hook
class Section(Flowable):
    """Big section opener: number, title, one-line summary. Registers itself for the table of contents."""

    def __init__(self, num, title, lead):
        super().__init__()
        self.num, self.title, self.lead = num, title, lead
        self.w = CW
        self.lines = simpleSplit(lead, SERIF_I, 11.5, CW - 4)
        self.h = 54 + 16 * len(self.lines)

    def wrap(self, aw, ah):
        return self.w, self.h

    def draw(self):
        c = self.canv
        c.setFillColor(MELON)
        c.setFont(SERIF_B, 40)
        c.drawString(0, self.h - 42, f"{self.num:02d}" if isinstance(self.num, int) else str(self.num))
        c.setFillColor(DEEP)
        c.setFont(SERIF_B, 24)
        c.drawString(68 if isinstance(self.num, int) else 52, self.h - 38, self.title)
        c.setStrokeColor(MELON)
        c.setLineWidth(2)
        c.line(0, self.h - 52, 38, self.h - 52)
        c.setStrokeColor(RULE)
        c.setLineWidth(0.6)
        c.line(42, self.h - 52, self.w, self.h - 52)
        c.setFillColor(DIM)
        c.setFont(SERIF_I, 11.5)
        y = self.h - 70
        for ln in self.lines:
            c.drawString(0, y, ln)
            y -= 16


def H2(text):
    return Paragraph(text, ST["h2"])


def H3(text):
    return Paragraph(text, ST["h3"])


# ------------------------------------------------------------------ diagram primitives
class Diagram(Flowable):
    """Vector diagram: draw(c, w, h) gets the canvas with origin at the bottom-left of the figure."""

    def __init__(self, height, drawfn, caption=None, width=None):
        super().__init__()
        self.w = width or CW
        self.h = height
        self.fn = drawfn
        self.caption = caption

    def wrap(self, aw, ah):
        return self.w, self.h

    def draw(self):
        c = self.canv
        c.saveState()
        c.setFillColor(colors.white)
        c.setStrokeColor(RULE)
        c.setLineWidth(0.6)
        c.roundRect(0, 0, self.w, self.h, 5, fill=1, stroke=1)
        self.fn(c, self.w, self.h)
        c.restoreState()


def figure(height, drawfn, caption):
    return KeepTogether([Diagram(height, drawfn), Paragraph(caption, ST["cap"])])


def box(c, x, y, w, h, title, sub=None, fill=MINT, stroke=GREEN, tcol=DEEP, tsize=8.6, ssize=7.2, bold=True, r=4, scol=DIM):
    c.setFillColor(fill)
    c.setStrokeColor(stroke)
    c.setLineWidth(0.9)
    c.roundRect(x, y, w, h, r, fill=1, stroke=1)
    tl = simpleSplit(title, SANS_B if bold else SANS, tsize, w - 8)
    sl = simpleSplit(sub, SANS, ssize, w - 8) if sub else []
    tot = len(tl) * (tsize + 2.2) + len(sl) * (ssize + 1.8)
    yy = y + h / 2 + tot / 2 - tsize
    c.setFillColor(tcol)
    c.setFont(SANS_B if bold else SANS, tsize)
    for ln in tl:
        c.drawCentredString(x + w / 2, yy, ln)
        yy -= tsize + 2.2
    c.setFillColor(scol)
    c.setFont(SANS, ssize)
    yy += 0.6
    for ln in sl:
        c.drawCentredString(x + w / 2, yy, ln)
        yy -= ssize + 1.8


def label(c, x, y, s, size=7.2, col=DIM, font=None, anchor="l"):
    c.setFillColor(col)
    c.setFont(font or SANS, size)
    {"l": c.drawString, "c": c.drawCentredString, "r": c.drawRightString}[anchor](x, y, s)


def arrow(c, x1, y1, x2, y2, col=DEEP, w=1.1, dash=None, head=5):
    import math
    c.setStrokeColor(col)
    c.setFillColor(col)
    c.setLineWidth(w)
    if dash:
        c.setDash(*dash)
    ang = math.atan2(y2 - y1, x2 - x1)
    xe, ye = x2 - head * 0.8 * math.cos(ang), y2 - head * 0.8 * math.sin(ang)
    c.line(x1, y1, xe, ye)
    c.setDash()
    p = c.beginPath()
    p.moveTo(x2, y2)
    p.lineTo(x2 - head * math.cos(ang - 0.38), y2 - head * math.sin(ang - 0.38))
    p.lineTo(x2 - head * math.cos(ang + 0.38), y2 - head * math.sin(ang + 0.38))
    p.close()
    c.drawPath(p, fill=1, stroke=0)


def lane(c, x, y, w, h, title, col=GREEN):
    c.setFillColor(colors.HexColor("#FBF6F0"))
    c.setStrokeColor(RULE)
    c.setLineWidth(0.6)
    c.setDash(2, 2)
    c.roundRect(x, y, w, h, 5, fill=1, stroke=1)
    c.setDash()
    label(c, x + 6, y + h - 10, title.upper(), 6.8, col, SANS_B)
