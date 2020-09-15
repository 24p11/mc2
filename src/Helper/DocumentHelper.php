<?php
namespace MC2\Core\Helper;
class DocumentHelper{
    
    public static function getCategoriesLibelles(){
        return [
            "120" => "CR de sejour hospitalier",
            "201" => "CR (ou fiche) de consultation",
            "301" => "CR d'anatomo-pathologie",
            "402" => "CR operatoire, CR d'accouchement",
            "309" => "CR d'acte diagnostique (autres)",
            "119" => "Synthese d'episode",
            "111" => "Lettre de sortie",
            "319" => "Resultat d'examen (autres)",
            "801" => "Autre document, source medicale",
            "302" => "CR de radiologie/imagerie",
            "521" => "Notification, Certificat",
            "409" => "CR d'acte therapeutique (autres)",
            "421" => "Prescription de medicaments",
            "429" => "Prescription, autre",
            "511" => "Demande d'examen",
            "422" => "Prescription de soins",
            "431" => "Dispensation de medicaments",
            "311" => "Resultats de biologie",
            "401" => "CR d'anesthesie",
            "203" => "CR de consultation d'anesthesie",
            "411" => "Pathologie(s) en cours",
            "439" => "Dispensation, autre"
        ];
    }
}