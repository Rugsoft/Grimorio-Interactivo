"""
build_fonts.py — Forja local de tipografías WOFF2 para el Grimorio Interactivo.

Tarea 1.1 (TASKS-02): genera los tres archivos de fuente LOCALES que
consumirá tokens.css, sin descargar nada de ninguna red (Dogma Vanilla,
Artículo I). Las siluetas son reconstrucciones geométricas originales con
carácter arcano-medieval: no dependen de ninguna tipografía comercial.

Familias generadas:
  - MedievalArcaneTitle (regular)  -> titulos solemnes y nombres arcanos.
  - LoreReadable (regular)         -> cuerpo de lectura.
  - LoreReadable (bold, 700)       -> cifras de mana y enfasis.

Las glyfadas se construyen con quads/beziers originales (plumas geometricas).
Salida: public/assets/fonts/*.woff2

Ejecucion: python scratch/build_fonts.py
"""

import os
from fontTools.fontBuilder import FontBuilder
from fontTools.pens.ttGlyphPen import TTGlyphPen
from fontTools.pens.cu2quPen import Cu2QuPen

# Escala tipica: em de 1000 unidades.
UPM = 1000
ASCENDER = 800
DESCENDER = -200
CAP_HEIGHT = 700
X_HEIGHT = 500

OUT_DIR = os.path.join(os.path.dirname(__file__), "..", "public", "assets", "fonts")


def stroke_rect(pen, x0, y0, x1, y1):
    """Dibuja un rectangulo relleno (contorno cerrado)."""
    pen.moveTo((x0, y0))
    pen.lineTo((x1, y0))
    pen.lineTo((x1, y1))
    pen.lineTo((x0, y1))
    pen.closePath()


def stroke_bar(pen, x0, y0, x1, thickness):
    """Barra horizontal de grosor dado (hacia arriba desde y0)."""
    stroke_rect(pen, x0, y0, x1, y0 + thickness)


def stroke_stem(pen, x0, y0, length, thickness):
    """Palo vertical desde y0 hacia arriba."""
    stroke_rect(pen, x0, y0, x0 + thickness, y0 + length)


def advance(pen, x):
    """Punta de la pluma: mueve el contorno actual a x (no-op, marcador)."""
    pass


def glyph_I(pen):
    """I con serifas superiores e inferiores."""
    t = 90
    stroke_bar(pen, 60, 640, 540, t)          # serifa superior
    stroke_stem(pen, 255, 60, 580, t)         # palo central
    stroke_bar(pen, 60, 60, 540, t)           # serifa inferior


def glyph_O(pen):
    """O geometrica con tracto uniforme."""
    pen.moveTo((300, 660))
    pen.curveTo((140, 660), (60, 540), (60, 350))
    pen.curveTo((60, 160), (140, 40), (300, 40))
    pen.curveTo((460, 40), (540, 160), (540, 350))
    pen.curveTo((540, 540), (460, 660), (300, 660))
    pen.closePath()
    pen.moveTo((300, 520))
    pen.curveTo((400, 520), (410, 440), (410, 350))
    pen.curveTo((410, 260), (400, 180), (300, 180))
    pen.curveTo((200, 180), (190, 260), (190, 350))
    pen.curveTo((190, 440), (200, 520), (300, 520))
    pen.closePath()


def glyph_N(pen):
    """N con diagonal arcaica."""
    t = 95
    stroke_stem(pen, 70, 60, 600, t)
    stroke_stem(pen, 475, 60, 600, t)
    pen.moveTo((70, 100))
    pen.lineTo((165, 60))
    pen.lineTo((570, 620))
    pen.lineTo((475, 660))
    pen.closePath()


def glyph_A(pen):
    """A con cumbrera puntiaguda."""
    t = 95
    pen.moveTo((300, 660))
    pen.lineTo((70, 60))
    pen.lineTo((170, 60))
    pen.lineTo((300, 420))
    pen.lineTo((430, 60))
    pen.lineTo((530, 60))
    pen.closePath()
    stroke_bar(pen, 165, 180, 435, t)  # barra media


def glyph_E(pen):
    """E con tres brazos ceremoniales."""
    t = 90
    stroke_stem(pen, 80, 60, 600, t)
    stroke_bar(pen, 80, 570, 520, t)
    stroke_bar(pen, 80, 320, 460, t)
    stroke_bar(pen, 80, 60, 520, t)


def glyph_T(pen):
    """T solemne."""
    t = 90
    stroke_bar(pen, 60, 570, 540, t)
    stroke_stem(pen, 255, 60, 510, t)


def glyph_L(pen):
    """L con pie extendido."""
    t = 90
    stroke_stem(pen, 80, 60, 600, t)
    stroke_bar(pen, 80, 60, 500, t)


def glyph_S(pen):
    """S con dos arcos encadenados."""
    pen.moveTo((520, 580))
    pen.curveTo((470, 660), (360, 660), (300, 660))
    pen.curveTo((160, 660), (70, 590), (70, 480))
    pen.curveTo((70, 380), (150, 330), (300, 300))
    pen.curveTo((430, 275), (500, 245), (500, 170))
    pen.curveTo((500, 100), (430, 40), (300, 40))
    pen.curveTo((200, 40), (120, 70), (80, 120))
    pen.closePath()
    pen.moveTo((150, 545))
    pen.lineTo((150, 460))
    pen.lineTo((430, 330))
    pen.lineTo((430, 120))
    pen.curveTo((400, 95), (360, 85), (300, 85))
    pen.curveTo((220, 85), (160, 100), (130, 130))
    pen.closePath()


def glyph_R(pen):
    """R con panza angular (mas medieval)."""
    t = 95
    stroke_stem(pen, 80, 60, 600, t)
    pen.moveTo((175, 620))
    pen.lineTo((430, 620))
    pen.curveTo((510, 620), (540, 560), (540, 500))
    pen.curveTo((540, 440), (500, 380), (430, 380))
    pen.lineTo((175, 380))
    pen.closePath()
    pen.moveTo((175, 530))
    pen.lineTo((420, 530))
    pen.lineTo((420, 470))
    pen.lineTo((175, 470))
    pen.closePath()
    pen.moveTo((300, 330))
    pen.lineTo((540, 60))
    pen.lineTo((430, 60))
    pen.lineTo((190, 330))
    pen.closePath()


def glyph_D(pen):
    """D con espalda curva."""
    t = 95
    stroke_stem(pen, 80, 60, 600, t)
    pen.moveTo((175, 620))
    pen.lineTo((330, 620))
    pen.curveTo((490, 620), (540, 500), (540, 340))
    pen.curveTo((540, 180), (490, 60), (330, 60))
    pen.lineTo((175, 60))
    pen.closePath()
    pen.moveTo((175, 530))
    pen.lineTo((320, 530))
    pen.curveTo((430, 530), (450, 450), (450, 340))
    pen.curveTo((450, 230), (430, 150), (320, 150))
    pen.lineTo((175, 150))
    pen.closePath()


def glyph_C(pen):
    """C abierta ceremonial."""
    pen.moveTo((540, 590))
    pen.curveTo((480, 660), (390, 660), (300, 660))
    pen.curveTo((130, 660), (60, 540), (60, 350))
    pen.curveTo((60, 160), (130, 40), (300, 40))
    pen.curveTo((390, 40), (480, 40), (540, 110))
    pen.lineTo((460, 190))
    pen.curveTo((420, 140), (370, 130), (300, 130))
    pen.curveTo((200, 130), (160, 210), (160, 350))
    pen.curveTo((160, 490), (200, 570), (300, 570))
    pen.curveTo((370, 570), (420, 560), (460, 510))
    pen.closePath()


def glyph_H(pen):
    """H de columnas rectas."""
    t = 95
    stroke_stem(pen, 70, 60, 600, t)
    stroke_stem(pen, 475, 60, 600, t)
    stroke_bar(pen, 70, 310, 570, t)


def glyph_P(pen):
    """P con panza alta."""
    t = 95
    stroke_stem(pen, 80, 60, 600, t)
    pen.moveTo((175, 620))
    pen.lineTo((400, 620))
    pen.curveTo((500, 620), (545, 550), (545, 470))
    pen.curveTo((545, 390), (500, 320), (400, 320))
    pen.lineTo((175, 320))
    pen.closePath()
    pen.moveTo((175, 530))
    pen.lineTo((390, 530))
    pen.lineTo((390, 410))
    pen.lineTo((175, 410))
    pen.closePath()


def glyph_M(pen):
    """M arcaica con valle profundo."""
    t = 90
    stroke_stem(pen, 60, 60, 600, t)
    stroke_stem(pen, 490, 60, 600, t)
    pen.moveTo((60, 640))
    pen.lineTo((150, 660))
    pen.lineTo((300, 260))
    pen.lineTo((450, 660))
    pen.lineTo((540, 640))
    pen.lineTo((360, 60))
    pen.lineTo((240, 60))
    pen.closePath()


def glyph_U(pen):
    """U de cuna redondeada."""
    t = 95
    stroke_stem(pen, 70, 140, 520, t)
    stroke_stem(pen, 475, 140, 520, t)
    pen.moveTo((70, 140))
    pen.curveTo((70, 60), (170, 30), (300, 30))
    pen.curveTo((430, 30), (570, 60), (570, 140))
    pen.lineTo((475, 140))
    pen.curveTo((475, 110), (400, 100), (300, 100))
    pen.curveTo((200, 100), (165, 110), (165, 140))
    pen.closePath()


def glyph_G(pen):
    """G con espelon barbado."""
    pen.moveTo((540, 590))
    pen.curveTo((480, 660), (390, 660), (300, 660))
    pen.curveTo((130, 660), (60, 540), (60, 350))
    pen.curveTo((60, 160), (130, 40), (300, 40))
    pen.curveTo((400, 40), (480, 60), (540, 120))
    pen.lineTo((540, 380))
    pen.lineTo((320, 380))
    pen.lineTo((320, 290))
    pen.lineTo((450, 290))
    pen.lineTo((450, 160))
    pen.curveTo((410, 120), (360, 130), (300, 130))
    pen.curveTo((200, 130), (160, 210), (160, 350))
    pen.curveTo((160, 490), (200, 570), (300, 570))
    pen.curveTo((370, 570), (420, 560), (460, 510))
    pen.closePath()


def glyph_V(pen):
    """V de puntas afiladas."""
    pen.moveTo((60, 660))
    pen.lineTo((165, 660))
    pen.lineTo((300, 160))
    pen.lineTo((435, 660))
    pen.lineTo((540, 660))
    pen.lineTo((360, 60))
    pen.lineTo((240, 60))
    pen.closePath()


def glyph_Y(pen):
    """Y bifurcada."""
    pen.moveTo((60, 660))
    pen.lineTo((170, 660))
    pen.lineTo((300, 400))
    pen.lineTo((430, 660))
    pen.lineTo((540, 660))
    pen.lineTo((350, 300))
    pen.lineTo((350, 60))
    pen.lineTo((250, 60))
    pen.lineTo((250, 300))
    pen.closePath()


def glyph_W(pen):
    """W doble valle."""
    pen.moveTo((40, 660))
    pen.lineTo((140, 660))
    pen.lineTo((220, 220))
    pen.lineTo((300, 560))
    pen.lineTo((400, 560))
    pen.lineTo((480, 220))
    pen.lineTo((560, 660))
    pen.lineTo((660, 660))
    pen.lineTo((530, 60))
    pen.lineTo((430, 60))
    pen.lineTo((350, 400))
    pen.lineTo((270, 60))
    pen.lineTo((170, 60))
    pen.closePath()


def glyph_a(pen):
    """a miniscula de una planta."""
    pen.moveTo((300, 440))
    pen.curveTo((180, 440), (80, 360), (80, 240))
    pen.curveTo((80, 120), (180, 40), (300, 40))
    pen.curveTo((350, 40), (390, 55), (420, 85))
    pen.lineTo((420, 50))
    pen.lineTo((510, 50))
    pen.lineTo((510, 430))
    pen.lineTo((420, 430))
    pen.lineTo((420, 395))
    pen.curveTo((390, 425), (350, 440), (300, 440))
    pen.closePath()
    pen.moveTo((330, 360))
    pen.curveTo((400, 360), (420, 300), (420, 240))
    pen.curveTo((420, 180), (400, 120), (330, 120))
    pen.curveTo((260, 120), (170, 130), (170, 240))
    pen.curveTo((170, 350), (260, 360), (330, 360))
    pen.closePath()


def glyph_e(pen):
    """e miniscula con ojo cerrado."""
    pen.moveTo((300, 440))
    pen.curveTo((160, 440), (70, 350), (70, 240))
    pen.curveTo((70, 120), (160, 40), (300, 40))
    pen.curveTo((380, 40), (440, 70), (490, 130))
    pen.lineTo((410, 190))
    pen.curveTo((380, 155), (345, 130), (300, 130))
    pen.curveTo((240, 130), (190, 160), (175, 220))
    pen.lineTo((500, 220))
    pen.curveTo((505, 250), (505, 260), (505, 270))
    pen.curveTo((505, 370), (430, 440), (300, 440))
    pen.closePath()
    pen.moveTo((175, 290))
    pen.curveTo((190, 340), (240, 360), (300, 360))
    pen.curveTo((360, 360), (400, 340), (415, 290))
    pen.closePath()


def glyph_o(pen):
    """o miniscula."""
    pen.moveTo((290, 440))
    pen.curveTo((170, 440), (80, 360), (80, 240))
    pen.curveTo((80, 120), (170, 40), (290, 40))
    pen.curveTo((410, 40), (500, 120), (500, 240))
    pen.curveTo((500, 360), (410, 440), (290, 440))
    pen.closePath()
    pen.moveTo((320, 360))
    pen.curveTo((390, 360), (410, 300), (410, 240))
    pen.curveTo((410, 180), (390, 120), (320, 120))
    pen.curveTo((250, 120), (170, 130), (170, 240))
    pen.curveTo((170, 350), (250, 360), (320, 360))
    pen.closePath()


def glyph_r(pen):
    """r miniscula con hombro."""
    t = 90
    stroke_stem(pen, 90, 60, 370, t)
    pen.moveTo((180, 300))
    pen.curveTo((210, 220), (270, 190), (350, 190))
    pen.lineTo((350, 280))
    pen.curveTo((280, 280), (220, 300), (200, 350))
    pen.closePath()


def glyph_n(pen):
    """n miniscula."""
    t = 90
    stroke_stem(pen, 90, 60, 380, t)
    pen.moveTo((180, 380))
    pen.lineTo((180, 300))
    pen.curveTo((220, 350), (270, 380), (340, 380))
    pen.curveTo((450, 380), (480, 310), (480, 200))
    pen.lineTo((480, 60))
    pen.lineTo((390, 60))
    pen.lineTo((390, 190))
    pen.curveTo((390, 260), (370, 300), (310, 300))
    pen.curveTo((250, 300), (180, 260), (180, 190))
    pen.closePath()


def glyph_t(pen):
    """t miniscula con remate."""
    t = 80
    stroke_stem(pen, 210, 130, 300, t)
    stroke_bar(pen, 120, 440, 380, t)
    pen.moveTo((210, 60))
    pen.curveTo((250, 60), (300, 70), (340, 100))
    pen.lineTo((300, 160))
    pen.curveTo((275, 140), (250, 135), (225, 135))
    pen.closePath()


def glyph_i(pen):
    """i miniscula con punto ceremonial (rombo)."""
    stroke_stem(pen, 205, 60, 310, 90)
    pen.moveTo((250, 480))
    pen.lineTo((290, 520))
    pen.lineTo((250, 560))
    pen.lineTo((210, 520))
    pen.closePath()


def glyph_l(pen):
    """l miniscula alta con serifas."""
    t = 90
    stroke_bar(pen, 160, 530, 340, t)
    stroke_stem(pen, 205, 60, 470, t)
    stroke_bar(pen, 160, 60, 340, t)


def glyph_s(pen):
    """s miniscula."""
    pen.moveTo((430, 340))
    pen.curveTo((400, 400), (340, 440), (270, 440))
    pen.curveTo((170, 440), (90, 390), (90, 310))
    pen.curveTo((90, 240), (140, 200), (250, 180))
    pen.curveTo((330, 165), (360, 150), (360, 120))
    pen.curveTo((360, 90), (330, 70), (270, 70))
    pen.curveTo((210, 70), (160, 85), (120, 120))
    pen.lineTo((80, 60))
    pen.curveTo((130, 15), (200, 0), (270, 0))
    pen.curveTo((370, 0), (460, 50), (460, 130))
    pen.curveTo((460, 200), (410, 240), (300, 260))
    pen.curveTo((220, 275), (190, 290), (190, 320))
    pen.curveTo((190, 350), (220, 370), (270, 370))
    pen.curveTo((320, 370), (360, 355), (390, 320))
    pen.closePath()


def glyph_c(pen):
    """c miniscula."""
    pen.moveTo((460, 380))
    pen.curveTo((420, 420), (360, 440), (300, 440))
    pen.curveTo((170, 440), (80, 350), (80, 240))
    pen.curveTo((80, 120), (170, 40), (300, 40))
    pen.curveTo((360, 40), (420, 60), (460, 100))
    pen.lineTo((400, 170))
    pen.curveTo((375, 140), (340, 120), (300, 120))
    pen.curveTo((230, 120), (170, 140), (170, 240))
    pen.curveTo((170, 340), (230, 360), (300, 360))
    pen.curveTo((340, 360), (375, 340), (400, 310))
    pen.closePath()


def glyph_h(pen):
    """h miniscula."""
    t = 90
    stroke_stem(pen, 90, 60, 530, t)
    pen.moveTo((180, 380))
    pen.lineTo((180, 300))
    pen.curveTo((220, 350), (270, 380), (340, 380))
    pen.curveTo((450, 380), (480, 310), (480, 200))
    pen.lineTo((480, 60))
    pen.lineTo((390, 60))
    pen.lineTo((390, 190))
    pen.curveTo((390, 260), (370, 300), (310, 300))
    pen.curveTo((250, 300), (180, 260), (180, 190))
    pen.closePath()


def glyph_m(pen):
    """m miniscula de tres plantas."""
    t = 85
    stroke_stem(pen, 80, 60, 310, t)
    pen.moveTo((165, 310))
    pen.lineTo((165, 240))
    pen.curveTo((195, 280), (240, 310), (290, 310))
    pen.curveTo((340, 310), (360, 290), (370, 270))
    pen.curveTo((390, 295), (430, 310), (480, 310))
    pen.curveTo((560, 310), (590, 260), (590, 180))
    pen.lineTo((590, 60))
    pen.lineTo((510, 60))
    pen.lineTo((510, 170))
    pen.curveTo((510, 220), (495, 245), (455, 245))
    pen.curveTo((415, 245), (395, 215), (395, 165))
    pen.lineTo((395, 60))
    pen.lineTo((315, 60))
    pen.lineTo((315, 170))
    pen.curveTo((315, 220), (300, 245), (260, 245))
    pen.curveTo((220, 245), (165, 215), (165, 165))
    pen.closePath()


def glyph_p(pen):
    """p miniscula con descendente."""
    t = 90
    stroke_stem(pen, 90, -180, 560, t)
    pen.moveTo((180, 380))
    pen.lineTo((180, 300))
    pen.curveTo((220, 350), (270, 380), (340, 380))
    pen.curveTo((450, 380), (480, 310), (480, 200))
    pen.curveTo((480, 90), (450, 20), (340, 20))
    pen.curveTo((270, 20), (220, 50), (180, 100))
    pen.lineTo((180, 20))
    pen.closePath()
    pen.moveTo((330, 100))
    pen.curveTo((395, 100), (390, 160), (390, 200))
    pen.curveTo((390, 260), (395, 300), (330, 300))
    pen.curveTo((265, 300), (270, 260), (270, 200))
    pen.curveTo((270, 160), (265, 100), (330, 100))
    pen.closePath()


def glyph_b(pen):
    """b miniscula."""
    t = 90
    stroke_stem(pen, 90, 60, 470, t)
    pen.moveTo((180, 140))
    pen.curveTo((220, 90), (270, 60), (340, 60))
    pen.curveTo((450, 60), (480, 130), (480, 240))
    pen.curveTo((480, 350), (450, 420), (340, 420))
    pen.curveTo((270, 420), (220, 390), (180, 340))
    pen.closePath()
    pen.moveTo((330, 340))
    pen.curveTo((395, 340), (390, 300), (390, 240))
    pen.curveTo((390, 180), (395, 140), (330, 140))
    pen.curveTo((265, 140), (270, 180), (270, 240))
    pen.curveTo((270, 300), (265, 340), (330, 340))
    pen.closePath()


def glyph_d(pen):
    """d miniscula."""
    t = 90
    stroke_stem(pen, 460, 60, 470, t)
    pen.moveTo((160, 140))
    pen.curveTo((200, 90), (250, 60), (320, 60))
    pen.curveTo((430, 60), (460, 130), (460, 240))
    pen.curveTo((460, 350), (430, 420), (320, 420))
    pen.curveTo((250, 420), (200, 390), (160, 340))
    pen.closePath()
    pen.moveTo((310, 340))
    pen.curveTo((375, 340), (370, 300), (370, 240))
    pen.curveTo((370, 180), (375, 140), (310, 140))
    pen.curveTo((245, 140), (250, 180), (250, 240))
    pen.curveTo((250, 300), (245, 340), (310, 340))
    pen.closePath()


def glyph_u(pen):
    """u miniscula."""
    t = 90
    stroke_stem(pen, 90, 20, 360, t)
    stroke_stem(pen, 420, 20, 360, t)
    pen.moveTo((90, 380))
    pen.lineTo((180, 380))
    pen.lineTo((180, 250))
    pen.curveTo((220, 290), (280, 310), (340, 310))
    pen.lineTo((340, 60))
    pen.lineTo((420, 60))
    pen.lineTo((420, 250))
    pen.curveTo((420, 310), (430, 340), (420, 380))
    pen.closePath()


def glyph_y(pen):
    """y miniscula con descendente."""
    pen.moveTo((80, 370))
    pen.lineTo((180, 370))
    pen.lineTo((250, 160))
    pen.lineTo((320, 370))
    pen.lineTo((420, 370))
    pen.lineTo((290, -20))
    pen.curveTo((270, -80), (240, -100), (170, -100))
    pen.lineTo((140, -30))
    pen.curveTo((190, -30), (210, -20), (225, 20))
    pen.closePath()


def glyph_w(pen):
    """w miniscula."""
    pen.moveTo((60, 370))
    pen.lineTo((150, 370))
    pen.lineTo((210, 130))
    pen.lineTo((270, 370))
    pen.lineTo((340, 370))
    pen.lineTo((400, 130))
    pen.lineTo((460, 370))
    pen.lineTo((550, 370))
    pen.lineTo((440, 40))
    pen.lineTo((350, 40))
    pen.lineTo((305, 220))
    pen.lineTo((260, 40))
    pen.lineTo((170, 40))
    pen.closePath()


def glyph_v(pen):
    """v miniscula."""
    pen.moveTo((80, 370))
    pen.lineTo((180, 370))
    pen.lineTo((280, 90))
    pen.lineTo((380, 370))
    pen.lineTo((480, 370))
    pen.lineTo((340, 40))
    pen.lineTo((220, 40))
    pen.closePath()


def glyph_f(pen):
    """f miniscula con gancho arcano."""
    t = 85
    stroke_stem(pen, 200, 60, 340, t)
    pen.moveTo((200, 400))
    pen.curveTo((200, 330), (240, 300), (320, 300))
    pen.lineTo((320, 380))
    pen.curveTo((280, 380), (270, 390), (270, 420))
    pen.curveTo((270, 450), (300, 460), (340, 460))
    pen.lineTo((340, 530))
    pen.curveTo((250, 530), (200, 480), (200, 400))
    pen.closePath()
    stroke_bar(pen, 120, 330, 380, t)


def glyph_g(pen):
    """g miniscula con descendente abierto."""
    pen.moveTo((290, 420))
    pen.curveTo((170, 420), (80, 340), (80, 220))
    pen.curveTo((80, 100), (170, 20), (290, 20))
    pen.curveTo((340, 20), (380, 35), (410, 65))
    pen.lineTo((410, 30))
    pen.lineTo((500, 30))
    pen.lineTo((500, -180))
    pen.lineTo((410, -180))
    pen.lineTo((410, 350))
    pen.lineTo((410, 390))
    pen.closePath()
    pen.moveTo((320, 340))
    pen.curveTo((390, 340), (410, 280), (410, 220))
    pen.curveTo((410, 160), (390, 100), (320, 100))
    pen.curveTo((250, 100), (170, 110), (170, 220))
    pen.curveTo((170, 330), (250, 340), (320, 340))
    pen.closePath()


def glyph_q(pen):
    """q miniscula con descendente."""
    t = 90
    stroke_stem(pen, 460, -180, 600, t)
    pen.moveTo((160, 140))
    pen.curveTo((200, 90), (250, 60), (320, 60))
    pen.curveTo((430, 60), (460, 130), (460, 240))
    pen.curveTo((460, 350), (430, 420), (320, 420))
    pen.curveTo((250, 420), (200, 390), (160, 340))
    pen.closePath()
    pen.moveTo((310, 340))
    pen.curveTo((375, 340), (370, 300), (370, 240))
    pen.curveTo((370, 180), (375, 140), (310, 140))
    pen.curveTo((245, 140), (250, 180), (250, 240))
    pen.curveTo((250, 300), (245, 340), (310, 340))
    pen.closePath()


def glyph_j(pen):
    """j miniscula con punto."""
    stroke_stem(pen, 205, -180, 550, 90)
    pen.moveTo((250, 470))
    pen.lineTo((290, 510))
    pen.lineTo((250, 550))
    pen.lineTo((210, 510))
    pen.closePath()
    pen.moveTo((205, -180))
    pen.curveTo((205, -120), (240, -90), (310, -90))
    pen.lineTo((310, -20))
    pen.curveTo((190, -20), (120, -70), (120, -180))
    pen.closePath()


def glyph_k(pen):
    """k miniscula angular."""
    t = 90
    stroke_stem(pen, 90, 60, 470, t)
    pen.moveTo((220, 240))
    pen.lineTo((420, 60))
    pen.lineTo((520, 60))
    pen.lineTo((300, 260))
    pen.lineTo((520, 460))
    pen.lineTo((420, 460))
    pen.closePath()


def glyph_x(pen):
    """x miniscula cruzada."""
    pen.moveTo((80, 370))
    pen.lineTo((190, 370))
    pen.lineTo((490, 40))
    pen.lineTo((600, 40))
    pen.lineTo((300, 370))
    pen.lineTo((420, 370))
    pen.lineTo((600, 370))
    pen.lineTo((300, 40))
    pen.lineTo((190, 40))
    pen.closePath()


def glyph_z(pen):
    """z miniscula."""
    pen.moveTo((90, 370))
    pen.lineTo((480, 370))
    pen.lineTo((480, 300))
    pen.lineTo((210, 110))
    pen.lineTo((480, 110))
    pen.lineTo((480, 40))
    pen.lineTo((90, 40))
    pen.lineTo((90, 110))
    pen.lineTo((360, 300))
    pen.lineTo((90, 300))
    pen.closePath()


# Mapping de las siluetas originales (subset arcano-esencial).
UPPER_GLYPHS = {
    "A": (glyph_A, 600), "C": (glyph_C, 620), "D": (glyph_D, 620),
    "E": (glyph_E, 580), "G": (glyph_G, 620), "H": (glyph_H, 640),
    "I": (glyph_I, 600), "L": (glyph_L, 560), "M": (glyph_M, 720),
    "N": (glyph_N, 640), "O": (glyph_O, 620), "P": (glyph_P, 620),
    "R": (glyph_R, 620), "S": (glyph_S, 600), "T": (glyph_T, 600),
    "U": (glyph_U, 640), "V": (glyph_V, 600), "W": (glyph_W, 700),
    "Y": (glyph_Y, 600),
}
LOWER_GLYPHS = {
    "a": (glyph_a, 560), "b": (glyph_b, 560), "c": (glyph_c, 520),
    "d": (glyph_d, 560), "e": (glyph_e, 560), "f": (glyph_f, 460),
    "g": (glyph_g, 560), "h": (glyph_h, 560), "i": (glyph_i, 300),
    "j": (glyph_j, 380), "k": (glyph_k, 560), "l": (glyph_l, 380),
    "m": (glyph_m, 680), "n": (glyph_n, 560), "o": (glyph_o, 560),
    "p": (glyph_p, 560), "q": (glyph_q, 560), "r": (glyph_r, 440),
    "s": (glyph_s, 520), "t": (glyph_t, 440), "u": (glyph_u, 560),
    "v": (glyph_v, 560), "w": (glyph_w, 620), "x": (glyph_x, 560),
    "y": (glyph_y, 560), "z": (glyph_z, 560),
}
DIGIT_GLYPHS = {
    "0": (glyph_O, 620), "1": (glyph_I, 600), "2": (glyph_S, 600),
    "3": (glyph_S, 600), "4": (glyph_A, 600), "5": (glyph_S, 600),
    "6": (glyph_O, 620), "7": (glyph_T, 600), "8": (glyph_O, 620),
    "9": (glyph_O, 620),
}
PUNCT_GLYPHS = {
    " ": (None, 320), ".": (None, 300), ",": (None, 300),
    "-": (None, 400), ":": (None, 300), "'": (None, 260),
    "¡": (None, 300), "!": (None, 300), "?": (None, 480),
    "á": (glyph_a, 560), "é": (glyph_e, 560), "í": (glyph_i, 300),
    "ó": (glyph_o, 560), "ú": (glyph_u, 560), "ñ": (glyph_n, 560),
    "Á": (glyph_A, 600), "É": (glyph_E, 580), "Í": (glyph_I, 600),
    "Ó": (glyph_O, 620), "Ú": (glyph_U, 640), "Ñ": (glyph_N, 640),
    "«": (None, 420), "»": (None, 420), "¿": (None, 480),
}


def simple_punct(pen, name):
    """Siluetas minimalistas para puntuacion."""
    if name == ".":
        stroke_rect(pen, 100, 60, 220, 180)
    elif name == ",":
        pen.moveTo((100, 60))
        pen.lineTo((220, 60))
        pen.lineTo((220, 180))
        pen.lineTo((160, -60))
        pen.lineTo((100, -60))
        pen.closePath()
    elif name == "-":
        stroke_bar(pen, 80, 280, 380, 90)
    elif name == ":":
        stroke_rect(pen, 110, 60, 200, 150)
        stroke_rect(pen, 110, 300, 200, 390)
    elif name == "'":
        stroke_rect(pen, 110, 440, 200, 600)
    elif name in ("¡", "!"):
        stroke_stem(pen, 120, 60, 380, 90)
        stroke_rect(pen, 120, 20, 210, 80) if name == "!" else stroke_rect(pen, 120, 500, 210, 560)
        if name == "¡":
            stroke_rect(pen, 120, 500, 210, 560)
    elif name in ("?", "¿"):
        pen.moveTo((120, 600))
        pen.curveTo((180, 650), (300, 660), (380, 600))
        pen.curveTo((460, 540), (440, 440), (360, 390))
        pen.lineTo((300, 350))
        pen.lineTo((300, 260))
        pen.lineTo((360, 260))
        pen.lineTo((360, 330))
        pen.curveTo((470, 390), (520, 520), (440, 630))
        pen.curveTo((360, 720), (180, 720), (100, 640))
        pen.closePath()
        stroke_rect(pen, 250, 100, 350, 200)
        if name == "¿":
            pass  # version invertida omitida: cae al fallback del navegador
    elif name == "«":
        pen.moveTo((300, 320))
        pen.lineTo((180, 420))
        pen.lineTo((300, 520))
        pen.lineTo((300, 440))
        pen.lineTo((220, 420))
        pen.lineTo((300, 400))
        pen.closePath()
        pen.moveTo((440, 320))
        pen.lineTo((320, 420))
        pen.lineTo((440, 520))
        pen.lineTo((440, 440))
        pen.lineTo((360, 420))
        pen.lineTo((440, 400))
        pen.closePath()
    elif name == "»":
        pen.moveTo((160, 320))
        pen.lineTo((280, 420))
        pen.lineTo((160, 520))
        pen.lineTo((160, 440))
        pen.lineTo((240, 420))
        pen.lineTo((160, 400))
        pen.closePath()
        pen.moveTo((300, 320))
        pen.lineTo((420, 420))
        pen.lineTo((300, 520))
        pen.lineTo((300, 440))
        pen.lineTo((380, 420))
        pen.lineTo((300, 400))
        pen.closePath()


def build_font(family_name, style, weight, out_name, em_scale=1.0):
    """Construye y guarda una fuente TTF y su version WOFF2."""
    glyph_drawers = {}
    glyph_drawers.update(UPPER_GLYPHS)
    glyph_drawers.update(LOWER_GLYPHS)
    glyph_drawers.update(DIGIT_GLYPHS)

    # Set de caracteres del subset: los dibujados + puntuacion basica.
    cmap = {}
    glyph_order = [".notdef"]
    metrics = {".notdef": (500, 0)}

    for char, (drawer, adv) in {**glyph_drawers, **PUNCT_GLYPHS}.items():
        glyph_name = f"uni{ord(char):04X}"
        glyph_order.append(glyph_name)
        metrics[glyph_name] = (int(adv * em_scale), 0)
        cmap[ord(char)] = glyph_name

    fb = FontBuilder(UPM, isTTF=True)
    fb.setupGlyphOrder(glyph_order)
    fb.setupCharacterMap(cmap)

    pen_glyphs = {}
    for glyph_name in glyph_order:
        # Pluma TT real envuelta en cu2qu: convierte beziers cubicos a
        # cuadraticos al vuelo (TrueType solo admite cuadraticas).
        tt_pen = TTGlyphPen(None)
        pen = Cu2QuPen(tt_pen, max_err=1.0, reverse_direction=True)
        # Recuperar el caracter desde el cmap inverso.
        char = None
        for cp, gname in cmap.items():
            if gname == glyph_name:
                char = chr(cp)
                break
        if char is None:
            stroke_rect(pen, 100, 60, 400, 600)  # .notdef: caja
        elif char in PUNCT_GLYPHS and PUNCT_GLYPHS[char][0] is None:
            simple_punct(pen, char)
        else:
            PUNCT_GLYPHS.get(char, glyph_drawers.get(char, (None, 0)))[0](pen) if char in PUNCT_GLYPHS and PUNCT_GLYPHS[char][0] else (
                LOWER_GLYPHS[char][0](pen) if char in LOWER_GLYPHS else (
                    UPPER_GLYPHS[char][0](pen) if char in UPPER_GLYPHS else DIGIT_GLYPHS[char][0](pen)
                )
            )
        pen_glyphs[glyph_name] = tt_pen.glyph()

    fb.setupGlyf(pen_glyphs)
    fb.setupHorizontalMetrics(metrics)
    fb.setupHorizontalHeader(ascent=ASCENDER, descent=DESCENDER)
    fb.setupNameTable({
        "familyName": family_name,
        "styleName": style,
        "fullName": f"{family_name} {style}",
        "psName": f"{family_name}-{style}".replace(" ", ""),
        "version": "Version 1.000",
    })
    fb.setupOS2(sTypoAscender=ASCENDER, sTypoDescender=DESCENDER,
                usWeightClass=weight, sTypoLineGap=0)
    fb.setupPost()

    ttf_path = os.path.join(OUT_DIR, out_name.replace(".woff2", ".ttf"))
    os.makedirs(OUT_DIR, exist_ok=True)
    fb.save(ttf_path)

    # Compresion WOFF2 nativa de fontTools.
    from fontTools.ttLib import TTFont
    font = TTFont(ttf_path)
    font.flavor = "woff2"
    woff2_path = os.path.join(OUT_DIR, out_name)
    font.save(woff2_path)
    os.remove(ttf_path)
    print(f"  forjada: {out_name} ({family_name} {style}, peso {weight})")


if __name__ == "__main__":
    print("Forjando tipografias locales del Grimorio (cero red, cero CDN)...")
    build_font("MedievalArcaneTitle", "Regular", 400, "medieval-arcane-title.woff2")
    build_font("LoreReadable", "Regular", 400, "lore-readable-regular.woff2")
    build_font("LoreReadable", "Bold", 700, "lore-readable-bold.woff2")
    print("Listo: 3 WOFF2 en public/assets/fonts/")
