[CmdletBinding(DefaultParameterSetName = 'Inventory')]
param(
    [Parameter(ParameterSetName = 'Inventory')]
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [Parameter(Mandatory = $true, ParameterSetName = 'Compare')]
    [string[]]$BeforeLogs,
    [Parameter(Mandatory = $true, ParameterSetName = 'Compare')]
    [string[]]$AfterLogs,
    [Parameter(ParameterSetName = 'Compare')]
    [string]$OutputCsv
)

$ErrorActionPreference = 'Stop'

if ($PSCmdlet.ParameterSetName -eq 'Inventory') {
    $paths = @(
        'AGENTS.md',
        '.agents/rules/project-rules.mdc',
        '.agents/rules/language-and-doc-rules.mdc',
        '.agents/local-rules/runtime.local.mdc',
        '.agents/rules/deployment-safety.mdc',
        '.agents/rules/access-and-credentials.mdc',
        '.agents/rules/inertia-contracts.mdc'
    )
    $rows = foreach ($relativePath in $paths) {
        $path = Join-Path $ProjectRoot $relativePath
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { continue }
        $content = [System.IO.File]::ReadAllText($path)
        $bytes = [System.Text.Encoding]::UTF8.GetByteCount($content)
        [pscustomobject]@{ File = $relativePath; UTF8Bytes = $bytes; ApproxTokens = [math]::Ceiling($bytes / 4) }
    }
    $rows | Format-Table -AutoSize
    Write-Output 'ApproxTokens uses UTF-8 bytes / 4; this is a text-size estimate, not model usage.'
    return
}

function Read-UsageRecords([string[]]$Patterns, [string]$GroupName) {
    $files = @(Get-ChildItem -Path $Patterns -File | Sort-Object FullName -Unique)
    if ($files.Count -eq 0) { throw "No JSONL logs matched for $GroupName." }
    foreach ($file in $files) {
        $completions = @()
        $observedToolItems = 0
        foreach ($line in Get-Content -LiteralPath $file.FullName) {
            if ([string]::IsNullOrWhiteSpace($line)) { continue }
            try { $event = $line | ConvertFrom-Json }
            catch { throw "Invalid JSONL in $($file.FullName). Capture stdout separately from stderr." }
            if ($event.type -eq 'turn.completed') { $completions += $event }
            if ($event.type -eq 'item.completed' -and $event.item.type -in @('command_execution', 'mcp_tool_call', 'web_search')) {
                $observedToolItems++
            }
        }
        if ($completions.Count -ne 1) {
            throw "Expected exactly one completed fresh turn in $($file.FullName); found $($completions.Count)."
        }
        $usage = $completions[0].usage
        foreach ($field in @('input_tokens', 'output_tokens')) {
            if ($null -eq $usage.$field -or $usage.$field -lt 0) { throw "Missing or invalid $field in $($file.FullName)." }
        }
        $inputTokens = [long]$usage.input_tokens
        $outputTokens = [long]$usage.output_tokens
        $cachedTokens = $null
        $uncachedTokens = $null
        if ($null -ne $usage.cached_input_tokens) {
            $cachedTokens = [long]$usage.cached_input_tokens
            if ($cachedTokens -lt 0 -or $cachedTokens -gt $inputTokens) { throw "Invalid cached count in $($file.FullName)." }
            $uncachedTokens = $inputTokens - $cachedTokens
        }
        $totalTokens = $inputTokens + $outputTokens
        $totalSource = 'input_plus_output'
        if ($null -ne $usage.total_tokens) {
            $totalTokens = [long]$usage.total_tokens
            $totalSource = 'reported_total'
        }
        [pscustomobject]@{
            Group = $GroupName
            File = $file.FullName
            InputTokens = $inputTokens
            CachedInputTokens = $cachedTokens
            UncachedInputTokens = $uncachedTokens
            OutputTokens = $outputTokens
            ReasoningOutputTokens = $usage.reasoning_output_tokens
            TotalTokens = $totalTokens
            TotalSource = $totalSource
            ObservedToolItems = $observedToolItems
        }
    }
}

function Get-Median([object[]]$Values) {
    $sorted = @($Values | Where-Object { $null -ne $_ } | ForEach-Object { [double]$_ } | Sort-Object)
    if ($sorted.Count -eq 0) { return $null }
    $middle = [int][math]::Floor($sorted.Count / 2)
    if ($sorted.Count % 2) { return $sorted[$middle] }
    return ($sorted[$middle - 1] + $sorted[$middle]) / 2
}

$before = @(Read-UsageRecords $BeforeLogs 'without_project_rules')
$after = @(Read-UsageRecords $AfterLogs 'with_project_rules')
if ($before.Count -ne $after.Count) { throw 'Use equal numbers of matched runs in both groups.' }
if (@($after | Where-Object { $before.File -contains $_.File }).Count) { throw 'A log cannot belong to both groups.' }
$records = @($before) + @($after)
if ($OutputCsv) { $records | Export-Csv -LiteralPath $OutputCsv -NoTypeInformation -Encoding UTF8 }
$records | Format-Table Group, InputTokens, CachedInputTokens, UncachedInputTokens, OutputTokens, TotalTokens, ObservedToolItems -AutoSize
$comparison = foreach ($metric in @('InputTokens', 'CachedInputTokens', 'UncachedInputTokens', 'OutputTokens', 'ReasoningOutputTokens', 'TotalTokens', 'ObservedToolItems')) {
    $beforeValues = @($before | ForEach-Object { $_.$metric })
    $afterValues = @($after | ForEach-Object { $_.$metric })
    $baseline = Get-Median $beforeValues
    $treatment = Get-Median $afterValues
    $percent = $null
    if ($null -ne $baseline -and $null -ne $treatment -and $baseline -ne 0) {
        $percent = [math]::Round(100 * ($treatment - $baseline) / $baseline, 2)
    }
    [pscustomobject]@{ Metric = $metric; BeforeMedian = $baseline; AfterMedian = $treatment; ChangePercent = $percent; BeforeAvailable = @($beforeValues | Where-Object { $null -ne $_ }).Count; AfterAvailable = @($afterValues | Where-Object { $null -ne $_ }).Count }
}
$comparison | Format-Table -AutoSize
Write-Output 'Negative ChangePercent means lower reported usage. Missing counters remain unavailable, not zero.'
Write-Output 'Cached/reasoning counts are details: they are not added again to TotalTokens. Compare quality and failures separately; this is not a price or plan-quota calculator.'
