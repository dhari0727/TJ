#!/usr/bin/env python3
"""
Convert markdown to PDF using fpdf2
"""
import sys
import os

# Add the venv site-packages to path
venv_path = r"C:\xampp\htdocs\travel_journel\ml\venv\Lib\site-packages"
if venv_path not in sys.path:
    sys.path.insert(0, venv_path)

from fpdf import FPDF
import re

class MarkdownPDF(FPDF):
    def __init__(self):
        super().__init__()
        self.set_auto_page_break(auto=True, margin=20)
        # Use Arial fonts copied to user directory
        font_dir = 'C:/Users/HP/fonts/'
        self.add_font('Arial', '', font_dir + 'arial.ttf')
        self.add_font('Arial', 'B', font_dir + 'arialbd.ttf')
        self.add_font('Arial', 'I', font_dir + 'ariali.ttf')
        self.add_font('Arial', 'BI', font_dir + 'arialbi.ttf')
        
    def header(self):
        if self.page_no() > 1:
            self.set_font('Arial', 'I', 8)
            self.set_text_color(100, 100, 100)
            self.cell(0, 5, 'JourneyAI (Travel Journal) - Project Summary', align='C')
            self.ln(8)
    
    def footer(self):
        self.set_y(-15)
        self.set_font('Arial', 'I', 8)
        self.set_text_color(128, 128, 128)
        self.cell(0, 10, f'Page {self.page_no()}/{{nb}}', align='C')

    def write_markdown(self, md_text):
        lines = md_text.split('\n')
        in_code_block = False
        code_lines = []
        in_table = False
        table_rows = []
        
        for line in lines:
            # Handle code blocks
            if line.strip().startswith('```'):
                if not in_code_block:
                    in_code_block = True
                    code_lines = []
                else:
                    in_code_block = False
                    self.write_code_block(code_lines)
                continue
            
            if in_code_block:
                code_lines.append(line)
                continue
            
            # Handle tables
            if '|' in line and line.strip().startswith('|'):
                if not in_table:
                    in_table = True
                    table_rows = []
                table_rows.append(line)
                continue
            elif in_table:
                in_table = False
                self.write_table(table_rows)
                table_rows = []
                # Process this line normally
                self.process_line(line)
                continue
            
            self.process_line(line)
        
        # Handle any remaining table
        if in_table and table_rows:
            self.write_table(table_rows)
    
    def process_line(self, line):
        stripped = line.strip()
        
        # Empty line
        if not stripped:
            self.ln(4)
            return
        
        # Headers
        if stripped.startswith('# '):
            self.set_font('Arial', 'B', 18)
            self.set_text_color(0, 100, 150)
            self.cell(0, 10, stripped[2:], new_x="LMARGIN", new_y="NEXT")
            self.ln(4)
        elif stripped.startswith('## '):
            self.set_font('Arial', 'B', 15)
            self.set_text_color(0, 120, 160)
            self.cell(0, 8, stripped[3:], new_x="LMARGIN", new_y="NEXT")
            self.ln(3)
        elif stripped.startswith('### '):
            self.set_font('Arial', 'B', 12)
            self.set_text_color(0, 130, 170)
            self.cell(0, 7, stripped[4:], new_x="LMARGIN", new_y="NEXT")
            self.ln(2)
        elif stripped.startswith('#### '):
            self.set_font('Arial', 'B', 11)
            self.set_text_color(0, 140, 180)
            self.cell(0, 6, stripped[5:], new_x="LMARGIN", new_y="NEXT")
            self.ln(2)
        
        # Horizontal rule
        elif stripped in ('---', '***'):
            self.set_draw_color(200, 200, 200)
            self.line(10, self.get_y(), 200, self.get_y())
            self.ln(6)
        
        # Bullet points
        elif stripped.startswith('- ') or stripped.startswith('* '):
            self.set_font('Arial', '', 10)
            self.set_text_color(0, 0, 0)
            self.set_x(15)
            self.cell(5, 5, '\u2022')
            self.write_formatted_text(stripped[2:])
            self.ln(5)
        
        # Numbered lists
        elif re.match(r'^\d+\.\s', stripped):
            self.set_font('Arial', '', 10)
            self.set_text_color(0, 0, 0)
            self.set_x(15)
            self.cell(8, 5, re.match(r'^\d+', stripped).group() + '.')
            self.write_formatted_text(stripped[re.match(r'^\d+\.\s', stripped).end():])
            self.ln(5)
        
        # Bold/italic inline
        else:
            self.set_font('Arial', '', 10)
            self.set_text_color(0, 0, 0)
            self.write_formatted_text(line)
            self.ln(5)
    
    def write_formatted_text(self, text):
        """Handle **bold**, *italic*, `code` inline"""
        # Split by **bold**
        parts = re.split(r'(\*\*.*?\*\*)', text)
        for part in parts:
            if part.startswith('**') and part.endswith('**'):
                self.set_font('Arial', 'B', 10)
                self.write(5, part[2:-2])
            else:
                # Split by *italic*
                subparts = re.split(r'(\*.*?\*)', part)
                for subpart in subparts:
                    if subpart.startswith('*') and subpart.endswith('*'):
                        self.set_font('Arial', 'I', 10)
                        self.write(5, subpart[1:-1])
                    else:
                        # Split by `code`
                        codeparts = re.split(r'(`.*?`)', subpart)
                        for cp in codeparts:
                            if cp.startswith('`') and cp.endswith('`'):
                                self.set_font('DejaVu', '', 9)
                                self.set_fill_color(240, 240, 240)
                                self.cell(self.get_string_width(cp[1:-1]) + 2, 5, cp[1:-1], fill=True)
                            else:
                                self.set_font('Arial', '', 10)
                                self.write(5, cp)
    
    def write_code_block(self, lines):
        self.set_font('Arial', '', 8)
        self.set_fill_color(245, 245, 245)
        self.set_draw_color(200, 200, 200)
        y_start = self.get_y()
        max_width = 0
        for line in lines:
            w = self.get_string_width(line)
            if w > max_width:
                max_width = w
        max_width = min(max_width + 4, 190)
        
        for line in lines:
            x = self.get_x()
            y = self.get_y()
            self.rect(x, y, max_width, 5, 'DF')
            self.set_xy(x + 2, y)
            self.cell(max_width - 4, 5, line)
            self.ln(5)
        self.ln(4)
    
    def write_table(self, rows):
        if not rows:
            return
        
        # Parse table
        data = []
        for row in rows:
            cells = [c.strip() for c in row.split('|')]
            # Remove empty first/last from split
            if cells[0] == '':
                cells = cells[1:]
            if cells and cells[-1] == '':
                cells = cells[:-1]
            data.append(cells)
        
        if not data:
            return
        
        # Calculate column widths
        col_count = len(data[0])
        col_widths = [0] * col_count
        for row in data:
            for i, cell in enumerate(row):
                if i < col_count:
                    w = self.get_string_width(cell) + 4
                    if w > col_widths[i]:
                        col_widths[i] = w
        
        # Cap total width
        total = sum(col_widths)
        if total > 190:
            scale = 190 / total
            col_widths = [w * scale for w in col_widths]
        
        # Header row
        self.set_font('Arial', 'B', 8)
        self.set_fill_color(0, 100, 150)
        self.set_text_color(255, 255, 255)
        for i, cell in enumerate(data[0]):
            self.cell(col_widths[i], 6, cell, border=1, fill=True, align='C')
        self.ln()
        
        # Separator row (skip)
        # Data rows
        self.set_font('Arial', '', 7)
        self.set_text_color(0, 0, 0)
        fill = False
        for row in data[2:]:  # Skip header and separator
            self.set_fill_color(245, 245, 245) if fill else self.set_fill_color(255, 255, 255)
            max_h = 6
            for i, cell in enumerate(row):
                if i < col_count:
                    self.cell(col_widths[i], max_h, cell, border=1, fill=fill, align='L')
            self.ln()
            fill = not fill
        self.ln(4)


def main():
    input_file = r"C:\xampp\htdocs\travel_journel\PROJECT_SUMMARY_FOR_PDF.md"
    output_file = r"C:\xampp\htdocs\travel_journel\JourneyAI_Project_Summary.pdf"
    
    with open(input_file, 'r', encoding='utf-8') as f:
        md_content = f.read()
    
    pdf = MarkdownPDF()
    pdf.alias_nb_pages()
    pdf.add_page()
    pdf.write_markdown(md_content)
    pdf.output(output_file)
    print(f"PDF created: {output_file}")


if __name__ == '__main__':
    main()