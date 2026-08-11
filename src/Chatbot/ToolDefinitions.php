<?php

namespace App\Chatbot;

class ToolDefinitions
{
    public static function getTools(): array
    {
        return [
            self::tool(
                'get_sites_overview',
                'Statistiques globales des sites : total, sites critiques, trafic moyen, répartition par service (FO=Fibre Optique, FH=Faisceaux Hertziens, SHARED) et par classification.',
                ['service' => ['type' => 'string', 'enum' => ['FO', 'FH', 'SHARED'], 'description' => 'Filtrer par service. FH = Faisceaux Hertziens. FO = Fibre Optique. Optionnel.']],
                []
            ),
            self::tool(
                'get_site_details',
                "Détails complets d'un site précis : trafic, capacité, taux d'utilisation, criticité, statut.",
                ['site_name' => ['type' => 'string', 'description' => 'Nom exact ou partiel du site recherché']],
                ['site_name']
            ),
            self::tool(
                'get_critical_sites',
                "Liste des sites classés critiques (is_critical = true). Utilise cet outil pour toute question contenant 'critique', 'critical', 'en alerte', 'saturé'.",
                [
                    'service' => ['type' => 'string', 'enum' => ['FO', 'FH', 'SHARED'], 'description' => 'Filtrer par service, optionnel'],
                    'limit' => ['type' => 'integer', 'description' => 'Nombre max de résultats, défaut 20'],
                ],
                []
            ),
            self::tool(
                'get_site_ticket_membership',
                "Trouve à quel(s) ticket(s)/workflow(s) un site appartient, et le statut de ce site dans ce ticket. "
                . "Utilise SYSTÉMATIQUEMENT cet outil pour toute question sur l'appartenance, le rattachement, ou 'à quel workflow appartient le site X'.",
                ['site_name' => ['type' => 'string', 'description' => 'Nom du site']],
                ['site_name']
            ),
            self::tool(
                'get_tickets_by_status',
                "Liste des tickets (workflows) filtrés par statut. 'open'=ouvert, 'in_progress'=en cours, 'completed'=terminé, 'closed'=clôturé.",
                ['status' => ['type' => 'string', 'enum' => ['open', 'in_progress', 'completed', 'closed'], 'description' => 'Statut du ticket, optionnel (vide = tous)']],
                []
            ),
            self::tool(
                'get_ticket_details',
                "Détails complets d'un ticket précis : titre, statut, progression, tâches et sites associés.",
                ['ticket_id' => ['type' => 'integer', 'description' => 'ID du ticket']],
                ['ticket_id']
            ),
            self::tool(
                'get_monthly_ticket_stats',
                "Statistiques des tickets/workflows sur un mois donné. Utilise cet outil pour toute demande de rapport mensuel ou de bilan périodique.",
                [
                    'month' => ['type' => 'integer', 'description' => 'Mois (1-12)'],
                    'year' => ['type' => 'integer', 'description' => 'Année, ex: 2026'],
                ],
                ['month', 'year']
            ),
        ];
    }

    private static function tool(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => $required],
            ],
        ];
    }
}