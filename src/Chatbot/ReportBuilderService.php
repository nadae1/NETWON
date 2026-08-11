<?php

namespace App\Chatbot;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

class ReportBuilderService
{
    public function buildPdfResponse(string $question, string $answer, array $rawData): Response
    {
        $html = $this->buildSingleHtml($question, $answer, $rawData);
        return $this->renderPdf($html, 'rapport_chatbot_' . date('Ymd_His') . '.pdf');
    }

    public function buildFullConversationPdfResponse(array $exports): Response
    {
        $sections = '';
        foreach ($exports as $i => $exp) {
            $answerHtml = nl2br(htmlspecialchars($exp['answer'] ?? ''));
            $sections .= "
                <div class=\"turn\">
                    <div class=\"turn-number\">Échange " . ($i + 1) . "</div>
                    <div class=\"question-box\"><strong>Question :</strong> " . htmlspecialchars($exp['question'] ?? '') . "</div>
                    <div class=\"answer-box\"><strong>Réponse NetBot :</strong><br>{$answerHtml}</div>
                </div>";
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $this->baseCss() . '
.turn { margin-bottom: 18px; page-break-inside: avoid; }
.turn-number { font-size: 11px; color: #ff7900; font-weight: bold; text-transform: uppercase; margin-bottom: 6px; }
</style></head><body>
    <div class="header"><h1>NetBot — Transcript de conversation</h1><p>Généré le ' . date('d/m/Y H:i') . ' — ' . count($exports) . ' échange(s)</p></div>
    ' . $sections . '
    <div class="footer">Transcript généré automatiquement par NetBot.</div>
</body></html>';

        return $this->renderPdf($html, 'conversation_netbot_' . date('Ymd_His') . '.pdf');
    }

    private function renderPdf(string $html, string $filename): Response
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** Rend une valeur lisible : "COTRANS: 19, UNKNOWN: 3" au lieu du JSON brut */
    private function humanize(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                $parts = [];
                foreach ($value as $k => $v) {
                    $parts[] = ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . $this->humanize($v);
                }
                return implode(' — ', $parts);
            }
            return implode(', ', array_map(fn ($v) => $this->humanize($v), $value));
        }
        if (is_bool($value)) return $value ? 'Oui' : 'Non';
        if ($value === null) return '-';
        return (string) $value;
    }

    private function normalizeToRows(mixed $data): array
    {
        if (!is_array($data)) {
            return [['valeur' => $this->humanize($data)]];
        }
        if (array_is_list($data) && !empty($data) && is_array($data[0])) {
            return $data;
        }
        $flat = [];
        foreach ($data as $key => $value) {
            $flat[$key] = $this->humanize($value);
        }
        return [$flat];
    }

    private function buildSingleHtml(string $question, string $answer, array $rawData): string
    {
        $answerHtml = nl2br(htmlspecialchars($answer));
        $sections = '';

        foreach ($rawData as $toolName => $data) {
            $title = ucfirst(str_replace('_', ' ', preg_replace('/_\d+$/', '', $toolName)));
            $rows = $this->normalizeToRows($data);
            if (empty($rows)) continue;

            $headers = array_keys($rows[0]);
            $headHtml = implode('', array_map(fn ($h) => '<th>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $h))) . '</th>', $headers));
            $bodyHtml = '';
            foreach ($rows as $row) {
                $bodyHtml .= '<tr>' . implode('', array_map(
                    fn ($v) => '<td>' . htmlspecialchars($this->humanize($v)) . '</td>',
                    array_values($row)
                )) . '</tr>';
            }
            $sections .= "<div class=\"section\"><h2>{$title}</h2><table><thead><tr>{$headHtml}</tr></thead><tbody>{$bodyHtml}</tbody></table></div>";
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $this->baseCss() . '</style></head><body>
    <div class="header"><h1>Rapport NetBot</h1><p>Généré le ' . date('d/m/Y H:i') . '</p></div>
    <div class="question-box"><strong>Question :</strong> ' . htmlspecialchars($question) . '</div>
    <div class="answer-box"><strong>Réponse :</strong><br>' . $answerHtml . '</div>
    ' . $sections . '
    <div class="footer">Rapport généré automatiquement par NetBot.</div>
</body></html>';
    }

    private function baseCss(): string
    {
        return '
body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
.header { background: #ff7900; color: white; padding: 20px; text-align: center; border-radius: 6px; }
.header h1 { margin: 0; font-size: 22px; }
.header p { margin: 6px 0 0; font-size: 11px; }
.question-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin: 16px 0; }
.answer-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 14px; margin-bottom: 16px; }
.section { margin: 16px 0; }
h2 { font-size: 15px; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 5px; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th { background: #f1f5f9; padding: 7px; border: 1px solid #cbd5e1; text-align: left; font-size: 10px; }
td { padding: 6px; border: 1px solid #e2e8f0; font-size: 10px; }
.footer { margin-top: 25px; text-align: center; color: #64748b; font-size: 10px; }';
    }
}