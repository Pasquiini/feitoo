"""Generate only synthetic, non-sensitive DOCX fixtures (Python stdlib)."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import base64

ROOT = Path(__file__).parent
W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
CT = 'http://schemas.openxmlformats.org/package/2006/content-types'
PR = 'http://schemas.openxmlformats.org/package/2006/relationships'
def make(name, body, extras=None):
    extras = extras or {}
    overrides = ''.join(f'<Override PartName="/word/{part}" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.{part[:-4]}+xml"/>' for part in ['styles.xml', 'header.xml', 'footer.xml'] if 'word/'+part in extras)
    with ZipFile(ROOT / (name+'.docx'), 'w', ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', f'<Types xmlns="{CT}"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>{overrides}</Types>')
        z.writestr('_rels/.rels', f'<Relationships xmlns="{PR}"><Relationship Id="rId1" Type="{R}/officeDocument" Target="word/document.xml"/></Relationships>')
        z.writestr('word/document.xml', f'<w:document xmlns:w="{W}" xmlns:r="{R}"><w:body>{body}</w:body></w:document>')
        for path, content in extras.items(): z.writestr(path, content)

if __name__ == '__main__':
    make('simple', '<w:p><w:r><w:t>Documento sintetico Feitoo. Sem dados pessoais.</w:t></w:r></w:p>')
    make('styles', '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Titulo de exemplo</w:t></w:r></w:p><w:p><w:r><w:rPr><w:b/><w:i/></w:rPr><w:t>Texto em negrito e italico.</w:t></w:r></w:p>', {
        'word/styles.xml': f'<w:styles xmlns:w="{W}"><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style></w:styles>',
        'word/_rels/document.xml.rels': f'<Relationships xmlns="{PR}"><Relationship Id="styles" Type="{R}/styles" Target="styles.xml"/></Relationships>'})
    make('table', '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="3000"/><w:gridCol w:w="3000"/></w:tblGrid><w:tr><w:tc><w:p><w:r><w:t>Produto ficticio</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Quantidade: 2</w:t></w:r></w:p></w:tc></w:tr></w:tbl>')
    make('pages', '<w:p><w:r><w:t>Primeira pagina</w:t><w:br w:type="page"/><w:t>Segunda pagina</w:t><w:br w:type="page"/><w:t>Terceira pagina</w:t></w:r></w:p>')
    make('header-footer', '<w:p><w:r><w:t>Corpo de exemplo</w:t></w:r></w:p><w:sectPr><w:headerReference w:type="default" r:id="h1"/><w:footerReference w:type="default" r:id="f1"/></w:sectPr>', {
        'word/header.xml': f'<w:hdr xmlns:w="{W}"><w:p><w:r><w:t>Cabecalho Feitoo</w:t></w:r></w:p></w:hdr>',
        'word/footer.xml': f'<w:ftr xmlns:w="{W}"><w:p><w:r><w:t>Rodape de exemplo</w:t></w:r></w:p></w:ftr>',
        'word/_rels/document.xml.rels': f'<Relationships xmlns="{PR}"><Relationship Id="h1" Type="{R}/header" Target="header.xml"/><Relationship Id="f1" Type="{R}/footer" Target="footer.xml"/></Relationships>'})
    make('image', '<w:p><w:r><w:t>Imagem sintetica</w:t></w:r></w:p><w:p><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"><wp:extent cx="914400" cy="914400"/><wp:docPr id="1" name="Imagem sintetica"/><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="pixel.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="img"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="914400" cy="914400"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>', {
        'word/media/pixel.png': base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0foAAAAASUVORK5CYII='),
        'word/_rels/document.xml.rels': f'<Relationships xmlns="{PR}"><Relationship Id="img" Type="{R}/image" Target="media/pixel.png"/></Relationships>'})
