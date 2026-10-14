param([Parameter(Mandatory = $true)][string]$WorkbookPath)
$ErrorActionPreference = 'Stop'
$taskPath = (Resolve-Path -LiteralPath $WorkbookPath).Path
$taskExcel = $null
$taskBook = $null
try {
    $taskExcel = New-Object -ComObject Excel.Application
    $taskExcel.Visible = $false
    $taskExcel.DisplayAlerts = $false
    $taskExcel.AutomationSecurity = 3
    # Normal open, read-only; no repair mode or external link updates.
    $taskBook = $taskExcel.Workbooks.Open($taskPath, 0, $true)
    $taskNotes = $taskBook.Worksheets.Item('Notas')
    $taskItems = $taskBook.Worksheets.Item('Itens')
    [pscustomobject]@{
        ExcelVersion = $taskExcel.Version
        Sheets = $taskBook.Worksheets.Count
        NotesDataRows = $taskNotes.UsedRange.Rows.Count - 1
        ItemsDataRows = $taskItems.UsedRange.Rows.Count - 1
        AccessKeyIsText = $taskNotes.Range('A2').Value2 -is [string]
        IssuerIsText = $taskNotes.Range('F2').Value2 -is [string]
        DateIsNumeric = $taskNotes.Range('D2').Value2 -is [double]
        HasFormula = $taskNotes.UsedRange.HasFormula
        NormalReadOnlyOpen = $taskBook.ReadOnly
    } | ConvertTo-Json
} finally {
    if ($taskBook) { $taskBook.Close($false) }
    if ($taskExcel) { $taskExcel.Quit() }
    if ($taskItems) { [void][Runtime.InteropServices.Marshal]::FinalReleaseComObject($taskItems) }
    if ($taskNotes) { [void][Runtime.InteropServices.Marshal]::FinalReleaseComObject($taskNotes) }
    if ($taskBook) { [void][Runtime.InteropServices.Marshal]::FinalReleaseComObject($taskBook) }
    if ($taskExcel) { [void][Runtime.InteropServices.Marshal]::FinalReleaseComObject($taskExcel) }
}
