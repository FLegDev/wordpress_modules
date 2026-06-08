# Campaigns Redirector v1.6.8

Plugin WordPress modulaire pour redirection intelligente de campagnes newsletters avec tracking Matomo, anti-spam et optimisation performance.

## 🎯 Features

### Core
- Redirection `/campaigns?target=...&pk_campaign=...` vers URL finale
- Tracking Matomo automatique (pk_campaign, pk_source, pk_medium, pk_content, pk_keyword)
- Modes d'affichage : Logo animé ou Stealth (invisible)
- Normalisation robuste des URLs (gère espaces, double-encoding, etc.)
- Anti-loop protection

### Security (Module)
- Détection scanners (SafeLinks, Proofpoint, Mimecast, etc.)
- Ban IP temporaire scanners (TTL configurable 60-3600s)
- Blocage des méthodes non-GET, des headers de prélecture/prérendu et des User-Agents vides
- Listes User-Agent scanners/automatisation configurables depuis l'admin
- Allowlist domaines robuste (anti open-redirect, www équivalent, wildcard `*.domain.tld`)
- Anti-burst adouci pour éviter de bloquer les clics humains derrière une même IP
- Blocage des User-Agents automatisés/bizarres (`curl`, `wget`, `python-requests`, etc.)
- Blocage silencieux (204) pour réduire charge serveur

### Performance (Module)
- Support Cloudflare Page Rules
- Static Shell HTML (zéro exécution PHP)
- Détection de fichier statique périmé après changement de configuration
- Configuration Nginx automatique

### Logger (Module)
- Enregistrement événements (OK/REJECT)
- Anonymisation IP (RGPD)
- Logs max entries (10-2000)
- Interface admin avec tableau détaillé

### Diagnostic (Admin)
- Test d'un lien complet `/campaigns?...` avant envoi
- Affichage `target_raw`, `target_final`, méthode de normalisation, allowlist, loop et paramètres `pk_*`

## 📦 Installation

1. Upload du dossier `campaigns-redirector` dans `/wp-content/plugins/`
2. Activer le plugin dans WordPress
3. Aller dans **Réglages → Campaigns Redirector**
4. Configurer l'URL Matomo et le Site ID

## ⚙️ Configuration

### 1. Core Settings (obligatoire)

```
URL Matomo: https://matomo.parresia.fr/
Site ID: 10
Mode: Logo / Stealth
Logo URL: (optionnel)
```

### 2. Security Settings (recommandé)

```
✅ Module activé
✅ Blocage scanners
✅ Ban IP temporaire (300s)
✅ Bloquer User-Agent vide
❌ Allowlist (activé si besoin)
```

Les motifs User-Agent sont volontairement ciblés. Ne pas ajouter de motifs trop larges comme
`apple`, `google`, `chrome`, `safari` ou `mozilla`, car ils apparaissent dans de vrais navigateurs.

### 3. Performance Settings (optionnel)

**Option A : Cloudflare Page Rules (recommandé)**

1. Cloudflare Dashboard → Rules → Page Rules
2. URL pattern: `*votre-site.fr/campaigns*`
3. Settings:
   - Cache Level: **Cache Everything**
   - Edge Cache TTL: **30 seconds**
   - Browser Cache TTL: **10 seconds**
   - Bypass Cache on Cookie: `wp-*|wordpress_*|comment_*`

**Option B : Static Shell (performance extrême)**

1. Activer le module Performance
2. Cocher "Static Shell"
3. Cliquer "Générer le fichier statique"
4. Demander à l'hébergeur d'ajouter la règle Nginx affichée

### 4. Logger Settings (debug)

```
❌ Logs désactivés (en prod)
✅ Logger REJECT (si logs activés)
❌ Anonymiser IP (activer si RGPD strict)
Max entries: 200
```

## 🚀 Usage

### Lien de campagne standard

```
https://votre-site.fr/campaigns?
  pk_campaign=Newsletter_Janvier
  &pk_source=email
  &pk_medium=newsletter
  &pk_content=link1
  &target=https://destination.com/page/?utm_source=brevo&utm_campaign=test
```

### Notes importantes

- `target` doit être le **dernier** paramètre si l'URL contient des `&` non encodés
- Les URLs HTTPS sont acceptées. Les anciennes URLs `http://` sont converties en `https://` avant validation.
- Les espaces dans les paramètres sont automatiquement gérés

## 🔧 Migration depuis v1.6.2

La migration est **automatique** au premier chargement du plugin :

- `crms_options` → Split en 4 modules (core, security, performance, logger)
- `crms_logs` → Conservé tel quel
- Aucune perte de données

## 📁 Structure

```
campaigns-redirector/
├── campaigns-redirector.php      # Bootstrap
├── includes/
│   ├── class-core.php            # Redirection + Matomo
│   ├── class-logger.php          # Logs + RGPD
│   ├── class-security.php        # Anti-spam + Allowlist
│   ├── class-performance.php     # Cache + Static Shell
│   └── class-admin.php           # Interface admin
└── README.md
```

## 🛠️ Développement

### Constants

```php
// Dans wp-config.php
define('CAMPAIGNS_REDIRECTOR_MATOMO_URL', 'https://matomo.custom.fr/');
define('CAMPAIGNS_REDIRECTOR_MATOMO_SITE_ID', '99');
define('CAMPAIGNS_REDIRECTOR_MODE', 'stealth');
define('CAMPAIGNS_REDIRECTOR_LOGO_URL', 'https://cdn.com/logo.svg');
```

### Filters

```php
// Modifier l'URL Matomo
add_filter('campaigns_redirector_matomo_url', function($url) {
    return 'https://custom-matomo.fr/';
});

// Modifier le Site ID
add_filter('campaigns_redirector_matomo_site_id', function($id) {
    return '42';
});
```

## ⚠️ Troubleshooting

### Page WordPress `/campaigns` existante

**Situation :** Une page WordPress nommée "campaigns" existe.

**Comportement :** Le plugin intercepte uniquement `/campaigns` quand un paramètre `target=` est présent. Sans `target`, la page WordPress active reste servie normalement.

### Redirection ne fonctionne pas

1. Vérifier que l'URL target commence par `https://`
2. Activer les logs dans l'onglet Logger
3. Vérifier la colonne "reason" dans les logs

### Static Shell ne fonctionne pas

1. Vérifier que Nginx est configuré avec la règle affichée
2. Tester : `curl -I https://site.fr/campaigns?target=...`
3. Si erreur 404 → Nginx pas configuré
4. Si 200 avec PHP → Nginx configuré mais fichier pas trouvé

## 📊 Monitoring

### Cloudflare Analytics

Websites → Analytics → Caching
- Cache Hit Ratio (doit être >70% après envoi NL)
- Bandwidth Saved

### Logs WordPress

Onglet Logs → Tableau avec colonnes :
- Status (OK/REJECT)
- Reason (redirect, scanner_block, invalid_target_url, etc.)
- normalize_method (as_is, rawurldecode_1, etc.)
- Méthode HTTP et signaux de prélecture (`Purpose`, `Sec-Purpose`, etc.)
- target_final / target_raw
- IP, UA, pk_*

## 🔐 Sécurité

- Validation HTTPS stricte
- Anti-loop (refuse redirection vers /campaigns sur même host)
- Scanner detection (méthodes non-GET, UA patterns configurables, prefetch/prerender)
- Allowlist domaines (optionnel)
- Rate limiting via ban IP temporaire

## 📝 Changelog

### v1.6.8 (2026-06-05)
- Sécurité plus stricte pour les campagnes newsletters
- Blocage de toutes les méthodes non-GET (`HEAD`, `OPTIONS`, `POST`, etc.)
- Blocage configurable des User-Agents vides
- Détection des headers `Purpose`, `X-Purpose`, `Sec-Purpose`, `X-Moz` contenant `prefetch`, `prerender` ou `preview`
- Listes User-Agent scanners/automatisation éditables dans l'admin
- Ajout de signatures ciblées Google/Apple/Microsoft/Proofpoint/Mimecast sans bloquer les mots génériques `apple` ou `google`
- Logs enrichis avec méthode HTTP et signaux de prélecture

### v1.6.3 (2025-01-28)
- Architecture modulaire (5 classes séparées)
- Migration automatique depuis v1.6.2
- Interface admin avec onglets
- Module Performance avec Static Shell
- Documentation Cloudflare intégrée

### v1.6.7 (2026-05-11)
- Ajout onglet Diagnostic de lien avant envoi
- Allowlist plus robuste (`www` équivalent, wildcard sous-domaines, URLs acceptées en saisie)
- Anti-burst moins agressif pour les vrais clics humains
- UA vide traité comme neutre, UA automatisés/bizarres bloqués en 204
- Static Shell marqué comme périmé après changement de configuration
- Compatibilité renforcée avec une page WordPress `/campaigns` existante

### v1.6.2
- Fix espaces dans utm_campaign
- Anonymisation IP optionnelle (RGPD)
- Scanner detection optimisée
- Allowlist avec www + sans www

### v1.6.1
- Logs avec détails complets
- Anti-burst avec ban IP
- Cache options en mémoire

## 📄 License

GPL-2.0+

## 👨‍💻 Author

FLegDevFr - PARRESIA
