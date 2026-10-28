param([Parameter(Mandatory=$true)][string]$InputPath,[Parameter(Mandatory=$true)][string]$OutputPath,[string]$SigningText,[string]$SignaturePath,[string]$EvidencePath,[string]$SelfiePath)
$ErrorActionPreference='Stop'
$word=$null
$document=$null
try {
    $word=New-Object -ComObject Word.Application
    $word.Visible=$false
    $word.DisplayAlerts=0
    $word.AutomationSecurity=3
    $document=$word.Documents.Open([System.IO.Path]::GetFullPath($InputPath),$false,$true,$false)
    if ($SigningText) {
        if (!$document.Bookmarks.Exists('assinatura_contratante')) { throw 'Campo de assinatura ausente no Word original preparado.' }
        if ($SignaturePath) {
            $range=$document.Bookmarks.Item('assinatura_contratante').Range.Paragraphs.Item(1).Range
            $range.Collapse(1)
            $picture=$document.InlineShapes.AddPicture([System.IO.Path]::GetFullPath($SignaturePath),$false,$true,$range)
            $picture.LockAspectRatio=-1
            $picture.Width=220
            if ($picture.Height -gt 70) { $picture.Height=70 }
            $after=$picture.Range.Duplicate
            $after.Collapse(0)
            $after.InsertAfter([string][char]11)
        }
    }
    if ($EvidencePath) {
        # Final page with the electronic signature record. The UTF-8 file keeps accents out of the command line;
        # its first line is the title.
        $lines=[System.IO.File]::ReadAllLines([System.IO.Path]::GetFullPath($EvidencePath),[System.Text.Encoding]::UTF8)
        $end=$document.Content
        $end.Collapse(0)
        $end.InsertBreak(7)
        $start=$document.Content.End-1
        # Word drops "space before" at the top of a page, so blank lines keep the title clear of the header logo.
        $gap=4
        $document.Range($start,$start).InsertAfter(([string][char]13)*$gap+($lines -join [string][char]13))
        $block=$document.Range($start,$document.Content.End)
        $block.Font.Size=9
        $block.Font.Bold=0
        $block.ParagraphFormat.SpaceBefore=0
        $block.ParagraphFormat.SpaceAfter=3
        $block.ParagraphFormat.Alignment=0
        # Same typeface as the contract signature block; the title clears the page header logo.
        $font='Century Gothic'
        if ($document.Bookmarks.Exists('assinatura_contratante')) { $name=$document.Bookmarks.Item('assinatura_contratante').Range.Font.Name; if ($name) { $font=$name } }
        $block.Font.Name=$font
        $title=$document.Range($start+$gap,$start+$gap+$lines[0].Length)
        $title.Font.Size=11
        $title.Font.Bold=1
        $title.ParagraphFormat.SpaceAfter=10
        if ($SelfiePath) {
            $document.Content.InsertParagraphAfter()
            $at=$document.Content
            $at.Collapse(0)
            $photo=$document.InlineShapes.AddPicture([System.IO.Path]::GetFullPath($SelfiePath),$false,$true,$at)
            $photo.LockAspectRatio=-1
            $photo.Width=120
        }
    }
    $document.ExportAsFixedFormat([System.IO.Path]::GetFullPath($OutputPath),17)
} finally {
    if($document){$document.Close(0);[void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($document)}
    if($word){$word.Quit();[void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($word)}
}
