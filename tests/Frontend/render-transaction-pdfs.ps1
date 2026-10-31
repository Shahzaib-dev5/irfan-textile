# Render synthetic PHPUnit PDF fixtures using Windows' built-in PDF reader.
# No app server, business data, package installation or visible window is used.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Runtime.WindowsRuntime
[Windows.Data.Pdf.PdfDocument,Windows.Data.Pdf,ContentType=WindowsRuntime] | Out-Null
[Windows.Storage.StorageFile,Windows.Storage,ContentType=WindowsRuntime] | Out-Null
[Windows.Storage.Streams.InMemoryRandomAccessStream,Windows.Storage.Streams,ContentType=WindowsRuntime] | Out-Null
[Windows.Storage.Streams.DataReader,Windows.Storage.Streams,ContentType=WindowsRuntime] | Out-Null
function Wait-ErpPdfOperation($operation, [Type]$resultType) {
 $method = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 } | Select-Object -First 1
 $task = $method.MakeGenericMethod($resultType).Invoke($null,@($operation))
 $task.Wait()
 return $task.Result
}
function Wait-ErpPdfAction($operation) {
 $method = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and !$_.IsGenericMethod -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.FullName -eq 'Windows.Foundation.IAsyncAction' } | Select-Object -First 1
 $task = $method.Invoke($null,@($operation))
 $task.Wait()
}
$erpProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$erpPdfRoot = Join-Path $erpProjectRoot 'storage/framework/testing/transaction-pdf'
foreach ($erpPdf in (Get-ChildItem -LiteralPath $erpPdfRoot -Filter '*.pdf')) {
 $erpFile = Wait-ErpPdfOperation ([Windows.Storage.StorageFile]::GetFileFromPathAsync($erpPdf.FullName)) ([Windows.Storage.StorageFile])
 $erpDoc = Wait-ErpPdfOperation ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($erpFile)) ([Windows.Data.Pdf.PdfDocument])
 Write-Output "$($erpPdf.Name): $($erpDoc.PageCount) pages"
 $erpPage = $erpDoc.GetPage(0)
 $erpStream = New-Object Windows.Storage.Streams.InMemoryRandomAccessStream
 Wait-ErpPdfAction ($erpPage.RenderToStreamAsync($erpStream))
 $erpReader = [Windows.Storage.Streams.DataReader]::new($erpStream.GetInputStreamAt(0))
 Wait-ErpPdfOperation ($erpReader.LoadAsync([uint32]$erpStream.Size)) ([uint32]) | Out-Null
 $erpBytes = New-Object byte[] $erpStream.Size
 $erpReader.ReadBytes($erpBytes)
 [System.IO.File]::WriteAllBytes((Join-Path $erpPdfRoot ($erpPdf.BaseName+'.png')),$erpBytes)
 $erpReader.Dispose(); $erpStream.Dispose(); $erpPage.Dispose()
}
