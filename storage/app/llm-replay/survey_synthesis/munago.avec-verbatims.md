## Ce que disent les données

L'enquête MunaGo a produit **{{n_total}} fiches exploitables**, dont **{{n_valides}} valides** et
**{{n_hors_cible}} hors cible** ({{taux_hors_cible}} % des contacts). La durée médiane d'un entretien est de
**{{duree_mediane}} secondes**, dans la fenêtre de 12 à 18 minutes prévue par le protocole : les entretiens
ont donc été menés au rythme attendu, sans passage accéléré.

Le seul indicateur que le protocole considère comme un vrai signal d'intérêt — un acompte réellement versé —
s'établit à **{{taux_acompte}} % ({{acompte_n}}/{{acompte_d}})**. À côté, **{{oui_verbal_n}} personnes
({{oui_verbal_pct}} %) ont dit oui verbalement sans payer** et **{{non_clair_n}} ont dit non clairement**.
L'écart entre le déclaratif et le geste payant, qui était l'hypothèse de départ de l'étude, se retrouve donc
dans les données : sur cette question, les mots et l'argent ne disent pas la même chose.

La chaîne d'engagement se rétrécit ensuite à chaque étape : **{{retrait_n}} acomptes sur {{retrait_d}} ont
donné lieu à un retrait dans les 7 jours ({{taux_retrait}} %)** et **{{activation_n}} appareil(s) sur
{{activation_d}} retiré(s) étaient activés à J+14 ({{taux_activation}} %)**. Ces deux derniers ratios reposent
sur des effectifs à un chiffre : ils indiquent une direction, ils ne mesurent rien.

Côté prix, le point de rupture déclaré pour l'appareil a une **médiane de {{prix_rupture_mediane}} FCFA**
(n = {{prix_rupture_n}} réponses chiffrées). À 20 000 FCFA, **{{prix_appareil_acceptable_pct}} %** des
répondants jugent le prix « cher mais acceptable » et **{{prix_appareil_trop_cher_pct}} %
({{prix_appareil_trop_cher_n}} personnes)** le jugent « trop cher ». Sur l'abonnement, la modalité de refus la
plus citée est **2 000 FCFA/mois ({{abo_2000_pct}} %)**, c'est-à-dire le premier palier proposé : une part
notable de l'échantillon refuse l'abonnement quel que soit son montant.

Entre les deux formules, **{{option_a_n}} répondants ({{option_a_pct}} %) choisissent l'option A** — appareil
20 000 FCFA, abonnement 3 000 FCFA/mois, sans engagement long — plutôt que l'entrée à bas prix avec
engagement de 12 mois.

## Points saillants

- Taux d'acompte : **{{taux_acompte}} % ({{acompte_n}}/{{acompte_d}} fiches valides)**.
- Oui verbal sans paiement : **{{oui_verbal_n}} fiches ({{oui_verbal_pct}} %)** — comptées comme des non.
- Retrait sous 7 jours parmi les acomptes : **{{retrait_n}}/{{retrait_d}}**.
- Activation à J+14 parmi les appareils retirés : **{{activation_n}}/{{activation_d}}**.
- Prix de rupture médian de l'appareil : **{{prix_rupture_mediane}} FCFA** (n = {{prix_rupture_n}}).
- Frein le plus codé : **{{frein_1_label}}** — {{frein_1_n}} mentions ({{frein_1_pct}} % des
  {{q6_codes}} verbatims codés).
- Motif de refus le plus codé : **{{refus_1_label}}** — {{refus_1_n}} mentions ({{refus_1_pct}} % des
  {{q14_codes}} refus codés).
- A déjà vécu une perte de localisation d'un enfant : **{{d2_incident_oui_n}} répondants
  ({{d2_incident_oui_pct}} %)**.
- Le quota « maximum 5 fiches par réseau » est **dépassé : {{quota_reseau}} fiches** pour un plafond de 5.

## Signaux faibles et réserves

Le frein le plus souvent codé est **{{frein_1_label}}** ({{frein_1_n}} mentions) ; il faut le lire avec les
mots des parents plutôt qu'avec son étiquette :

> {{frein_1_quote}}

Le motif de refus le plus fréquent est **{{refus_1_label}}** ({{refus_1_n}} mentions) :

> {{refus_1_quote}}

Ces deux hiérarchies portent sur des mentions, pas sur des personnes : un même parent peut exprimer
plusieurs freins, et les réponses les plus courtes restent non codées.

Deux réserves de méthode pèsent sur toute lecture de ces chiffres. D'abord, **les effectifs de la chaîne
d'engagement sont minuscules** : {{acompte_n}} acomptes, {{retrait_n}} retraits, {{activation_n}} activation(s).
Un cas de plus ou de moins déplace les taux de dizaines de points ; ces trois ratios sont des observations,
pas des mesures. Ensuite, **le quota « 5 fiches par réseau » est dépassé ({{quota_reseau}}/5)** : une partie de
l'échantillon provient d'un même relais et partage donc probablement un profil socio-économique, ce qui affaiblit
la représentativité des freins exprimés.

Sur la vie privée, **{{q12_non_securite_pct}} %** des répondants déclarent que la localisation permanente ne les
dérange pas si elle sert la sécurité de l'enfant, tandis que **{{q12_oui_beaucoup_pct}} %** disent que cela les
dérange beaucoup. Le sujet n'est donc pas neutre, mais il n'est pas non plus le point de blocage principal —
les données montrent que le blocage se situe au moment du paiement, pas au moment de la présentation.

Enfin, **{{q7_portera_oui_pct}} %** des répondants pensent que l'enfant porterait l'appareil quotidiennement ;
cette réponse est déclarative et n'a été confrontée à aucune observation de terrain.

## Ce qu'il reste à vérifier

1. **La conversion acompte → retrait sur un effectif suffisant.** Avec {{retrait_d}} acomptes, le taux de
   retrait observé n'est pas interprétable. Il faudrait au moins une trentaine d'acomptes avant de conclure quoi
   que ce soit sur la capacité des familles à venir chercher l'appareil.
2. **L'effet du lieu de retrait.** Plusieurs refus citent la distance ; l'étude n'a pas fait varier le point de
   retrait et ne permet donc pas de séparer le refus du produit du refus du déplacement.
3. **Le comportement réel de port par l'enfant.** Aucune donnée d'usage n'a été collectée : seules des
   déclarations de parents existent.
4. **La sensibilité au prix d'abonnement.** Les modalités de refus proposées (2 000 à 6 000 FCFA/mois) ne
   permettent pas de situer un point de rupture sous 2 000 FCFA, alors que c'est la modalité la plus choisie.
5. **La solidité de l'échantillon hors réseau.** Refaire une vague en respectant le plafond de 5 fiches par
   réseau pour vérifier si les freins exprimés sont propres au relais ou généraux.
