<?php

declare(strict_types=1);

namespace App\Infrastructure\Reporting;

use App\Infrastructure\Persistence\Eloquent\Reporting\BusinessPackExport;
use RuntimeException;

final class BusinessPackRenderer
{
    /**
     * @return array{
     *   content:string,
     *   filename:string,
     *   mime_type:string,
     *   size_bytes:int,
     *   content_sha256:string
     * }
     */
    public function render(BusinessPackExport $export): array
    {
        $manifest = $export->frozen_manifest;

        if (! is_array($manifest)) {
            throw new RuntimeException(
                'Business Pack cannot render without a frozen manifest.',
            );
        }

        $language = (string) $export->output_language;
        $labels = $this->labels($language);
        $business = (array) ($manifest['business'] ?? []);
        $sources = (array) ($manifest['sources'] ?? []);
        $exclusions = (array) ($manifest['explicit_exclusions'] ?? []);

        $rows = '';

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $rows .= '<tr>'
                .'<td>'.$this->e((string) ($source['scope'] ?? '')).'</td>'
                .'<td>'.$this->e((string) ($source['source_kind'] ?? '')).'</td>'
                .'<td>'.$this->e((string) ($source['label'] ?? '')).'</td>'
                .'<td>'.$this->e((string) ($source['summary'] ?? '')).'</td>'
                .'<td><code>'.$this->e((string) ($source['source_id'] ?? '')).'</code></td>'
                .'<td><code>'.$this->e((string) ($source['source_hash'] ?? '')).'</code></td>'
                .'<td>'.$this->e((string) ($source['effective_from'] ?? '')).'</td>'
                .'</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7">'.$this->e($labels['no_sources']).'</td></tr>';
        }

        $exclusionRows = '';

        foreach ($exclusions as $exclusion) {
            if (! is_array($exclusion)) {
                continue;
            }

            $exclusionRows .= '<li>'
                .$this->e((string) ($exclusion['scope'] ?? ''))
                .' — '
                .$this->e((string) ($exclusion['reason'] ?? ''))
                .'</li>';
        }

        if ($exclusionRows === '') {
            $exclusionRows = '<li>'.$this->e($labels['none']).'</li>';
        }

        $manifestJson = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR
            | JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION,
        );

        $content = '<!doctype html>'
            .'<html lang="'.$this->e($language).'">'
            .'<head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$this->e($labels['title']).'</title>'
            .'<style>'
            .'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:32px;color:#0f172a;line-height:1.5}'
            .'h1,h2{margin:0 0 12px}h2{margin-top:28px;font-size:18px}'
            .'p{margin:6px 0}table{width:100%;border-collapse:collapse;margin-top:12px}'
            .'th,td{border:1px solid #cbd5e1;padding:8px;text-align:left;vertical-align:top;font-size:12px}'
            .'th{background:#f8fafc}code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}'
            .'pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f8fafc;border:1px solid #e2e8f0;padding:12px;font-size:11px}'
            .'.note{background:#fff7ed;border:1px solid #fed7aa;padding:12px;margin-top:20px}'
            .'</style></head><body>'
            .'<h1>'.$this->e($labels['title']).'</h1>'
            .'<p><strong>'.$this->e($labels['business']).':</strong> '
            .$this->e((string) ($business['name'] ?? '')).'</p>'
            .'<p><strong>'.$this->e($labels['business_id']).':</strong> '
            .'<code>'.$this->e((string) ($business['id'] ?? '')).'</code></p>'
            .'<p><strong>'.$this->e($labels['as_of']).':</strong> '
            .$this->e((string) ($manifest['as_of_at'] ?? '')).'</p>'
            .'<p><strong>'.$this->e($labels['language']).':</strong> '
            .$this->e($language).'</p>'
            .'<div class="note">'.$this->e($labels['representation_note']).'</div>'
            .'<h2>'.$this->e($labels['sources']).'</h2>'
            .'<table><thead><tr>'
            .'<th>'.$this->e($labels['scope']).'</th>'
            .'<th>'.$this->e($labels['kind']).'</th>'
            .'<th>'.$this->e($labels['label']).'</th>'
            .'<th>'.$this->e($labels['summary']).'</th>'
            .'<th>'.$this->e($labels['source_id']).'</th>'
            .'<th>'.$this->e($labels['hash']).'</th>'
            .'<th>'.$this->e($labels['effective']).'</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<h2>'.$this->e($labels['exclusions']).'</h2><ul>'
            .$exclusionRows.'</ul>'
            .'<h2>'.$this->e($labels['manifest']).'</h2>'
            .'<pre>'.$this->e($manifestJson).'</pre>'
            .'</body></html>';

        $filename = 'business-pack-'
            .(string) $export->business_id
            .'-'
            .(string) $export->getKey()
            .'.html';

        return [
            'content' => $content,
            'filename' => $filename,
            'mime_type' => 'text/html; charset=UTF-8',
            'size_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
        ];
    }

    /** @return array<string,string> */
    private function labels(string $language): array
    {
        return match ($language) {
            'my' => [
                'title' => 'thePBR OS Business Pack',
                'business' => 'လုပ်ငန်း',
                'business_id' => 'Business ID',
                'as_of' => 'အချက်အလက်အတည်ပြုချိန်',
                'language' => 'Output ဘာသာစကား',
                'representation_note' => 'ဤဖိုင်သည် frozen manifest မှ ထုတ်လုပ်ထားသော representation ဖြစ်ပြီး canonical truth မဟုတ်ပါ။ User ထည့်သွင်းထားသော data ကို အလိုအလျောက်ဘာသာမပြန်ထားပါ။',
                'sources' => 'ထည့်သွင်းထားသော Source များ',
                'scope' => 'Scope',
                'kind' => 'Source အမျိုးအစား',
                'label' => 'Label',
                'summary' => 'အကျဉ်းချုပ်',
                'source_id' => 'Source ID',
                'hash' => 'Hash',
                'effective' => 'Effective From',
                'exclusions' => 'ချန်လှပ်ထားသော Scope များ',
                'manifest' => 'Frozen Manifest',
                'no_sources' => 'Authorized source မရှိပါ။',
                'none' => 'မရှိပါ',
            ],
            'mixed' => [
                'title' => 'thePBR OS Business Pack',
                'business' => 'Business · လုပ်ငန်း',
                'business_id' => 'Business ID',
                'as_of' => 'As of · အတည်ပြုချိန်',
                'language' => 'Output language · ဘာသာစကား',
                'representation_note' => 'This file is a generated representation of the frozen manifest, not canonical truth. User-entered data is not auto-translated. · ဒီဖိုင်က frozen manifest ကို ကိုယ်စားပြုထုတ်ပေးထားတာဖြစ်ပြီး canonical truth မဟုတ်ပါ။ User data ကို အလိုအလျောက်ဘာသာမပြန်ပါ။',
                'sources' => 'Included Sources · Source များ',
                'scope' => 'Scope',
                'kind' => 'Source kind',
                'label' => 'Label',
                'summary' => 'Summary · အကျဉ်းချုပ်',
                'source_id' => 'Source ID',
                'hash' => 'Hash',
                'effective' => 'Effective From',
                'exclusions' => 'Explicit Exclusions · ချန်လှပ်မှု',
                'manifest' => 'Frozen Manifest',
                'no_sources' => 'No authorized source included.',
                'none' => 'None · မရှိပါ',
            ],
            default => [
                'title' => 'thePBR OS Business Pack',
                'business' => 'Business',
                'business_id' => 'Business ID',
                'as_of' => 'As of',
                'language' => 'Output language',
                'representation_note' => 'This file is a generated representation of the frozen manifest, not canonical truth. User-entered data is not auto-translated.',
                'sources' => 'Included Sources',
                'scope' => 'Scope',
                'kind' => 'Source kind',
                'label' => 'Label',
                'summary' => 'Summary',
                'source_id' => 'Source ID',
                'hash' => 'Hash',
                'effective' => 'Effective From',
                'exclusions' => 'Explicit Exclusions',
                'manifest' => 'Frozen Manifest',
                'no_sources' => 'No authorized source included.',
                'none' => 'None',
            ],
        };
    }

    private function e(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}
