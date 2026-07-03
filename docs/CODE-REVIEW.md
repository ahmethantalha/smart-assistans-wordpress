# Smart Assistant — Kod İnceleme, Güvenlik Denetimi ve Gelişim Planı

**Tarih:** 2026-07-03 · **İncelenen sürüm:** 1.0.3
**Kapsam:** Tüm eklenti PHP/JS kodu (üçüncü parti `plugin-update-checker` kütüphanesi hariç).

---

## 1. Özet

Eklenti genel olarak iyi organize edilmiş: namespace + autoloader, ayrık sorumluluk sınıfları, CI + PHPCS + PHPStan (level 5) + PHPUnit altyapısı mevcut. SQL sorguları `$wpdb->prepare` + `esc_like` ile korunmuş, frontend markdown render'ı HTML escape + güvenli protokol whitelist'i ile XSS'e karşı savunmalı, admin aksiyonları CSRF korumalı.

Buna karşın incelemede şunlar bulundu:

- **Kritik/yüksek güvenlik sorunları:** system-prompt enjeksiyonu, parola korumalı içerik sızıntısı, maliyet suistimali vektörleri, atomik olmayan rate limit, global option mutasyonu.
- **Mantık hataları:** biri kullanıcının `page` post type'ını hiç kaldıramamasına ve her istekte DB yazımına yol açıyor.
- **Kullanım / performans sorunları:** ölü kod, i18n uyuşmazlığı, N+1 sorgular.

Her bulgu dosya/satır referansı ve önerilen düzeltmeyle listelenmiştir. Sonda önceliklendirilmiş gelişim planı var.

> **Güncelleme (2026-07-03):** Aşağıdaki bulgular bu dalda düzeltildi:
> **G1, G2, G3, G4, G5** (tüm güvenlik) ve **M1, M2, M3, M4** (mantık hataları).
>
> Ayrıca **prompt injection & kişisel veri koruması** için çok katmanlı savunma eklendi:
> - `smart_assistant_security_preamble()` — ezilemez, sunucu-taraflı güvenlik önsözü
>   HER system prompt'una eklenir (parola/şifre/API anahtarı/kişisel veri ifşasını,
>   hesap/parola işlemlerini ve talimat-ezme denemelerini reddeder).
> - Kaynak metinler "yalnızca veri, talimat değil" olarak çerçevelenir (indirect injection).
> - `smart_assistant_redact_output()` — çıktıda kalan e-posta, gizli anahtar/token ve
>   yapılandırılmış API anahtarı maskelenir (filtrelerle ayarlanabilir).
> - `smart_assistant_looks_like_injection()` — olası injection denemeleri loglanır.
>
> Açık kalan: **M5** (MiniMax model kimlikleri dış doğrulama gerektiriyor) ve
> Faz 3 (RestController testleri, N+1 optimizasyonu).

---

## 2. Güvenlik Açıkları

### G1 — KRİTİK: İstemci `history` üzerinden `system` rolü enjekte edebiliyor
**Dosya:** `includes/class-rest-controller.php:401` (`normalize_history`), `includes/class-ai-client.php:44`

`normalize_history()` istemciden gelen geçmiş mesajlarda `system` rolüne izin veriyor:

```php
$role = in_array( $m['role'], [ 'user', 'assistant', 'system' ], true ) ? $m['role'] : 'user';
```

`AIClient::chat()` ise yalnızca `messages[0]` `system` **değilse** kendi kimlik/kural prompt'unu ekliyor. Sonuç: herhangi bir ziyaretçi `history[0] = { role: 'system', content: '...' }` göndererek:

1. Eklentinin tüm kimlik ve "sadece kaynaklara dayan" kurallarını **tamamen devre dışı bırakır**,
2. Public `/chat` endpoint'ini **kendi system prompt'uyla ücretsiz, genel amaçlı bir LLM proxy'si** olarak kullanır (site sahibinin API faturasıyla).

**Düzeltme:** `normalize_history` içinde yalnızca `user` ve `assistant` rollerine izin ver; `system` gelenleri at ya da `user`'a düşür. System prompt'u her zaman sunucu tarafında ekle.

### G2 — YÜKSEK: Parola korumalı yazıların içeriği sızıyor
**Dosya:** `includes/class-search.php` (`relevance_search:245`, `fallback_like_search:396`, `is_post_readable:101`)

Parola korumalı yazılar WordPress'te `post_status = 'publish'` + dolu `post_password` olarak tutulur. Arama SQL'leri yalnızca `post_status = 'publish'` filtreliyor, `is_post_readable()` da `publish` görünce `true` dönüyor. Sonuç: parola korumalı bir yazının **tam içeriği** hem `/chat` cevaplarına kaynak olarak, hem `/summarize` özetine, hem de Abilities API (`search_content`, `get_post`) çıktısına parolasız sızar.

**Düzeltme:** SQL'lere `AND post_password = ''` ekle; `is_post_readable()` içinde `! empty( $post->post_password )` ise yetki/parola kontrolü yap. `WP_Query` fallback'ine `'has_password' => false` ekle.

### G3 — YÜKSEK: Girdi uzunluğu sınırsız → token/maliyet istismarı
**Dosya:** `includes/class-rest-controller.php:147, 404`

`message` ve `history[].content` alanlarında uzunluk sınırı yok. `normalize_history` yalnızca son 10 mesajı alıyor ama her mesajın boyutu serbest. Kötü niyetli bir istek çok büyük bir gövde göndererek her çağrıda maliyeti şişirebilir (LLM token maliyeti + `optimize_context` yalnızca 4000 token'a kırpsa da girdi işleme maliyeti kalır).

**Düzeltme:** `message` için makul bir karakter üst sınırı (ör. 4000), `history[].content` için mesaj başına sınır uygula. Toplam history boyutunu da sınırla.

### G4 — YÜKSEK: Public endpoint korumaları zayıf ve kapatılabilir
**Dosya:** `includes/class-rest-controller.php:37, 310-382`

`/chat` ve `/summarize` `permission_callback => '__return_true'`. Tek koruma nonce + IP rate limit:

- WP nonce'ı sayfa HTML'inden kazınabilir (oturumsuz kullanıcılar için ~12-24 saat sabit).
- `smart_assistant_enforce_nonce` filtresiyle nonce zorunluluğu tamamen kapatılabiliyor; o durumda tek koruma rate limit.
- `check_rate_limit()` **atomik değil**: `get_transient` → `++` → `set_transient` arası race condition var; eşzamanlı isteklerde sayaç düşük kalır. Kalıcı object cache yoksa transient davranışı da güvenilmez.
- Rate limit yalnızca IP başına → botnet/dağıtık isteklerle aşılır.

**Düzeltme:** Rate limit'i atomik sayaca çevir (`wp_cache_incr` veya kilitli increment). Nonce kapatma filtresini kaldırmayı veya en azından belgelemeyi değerlendir. İsteğe bağlı: global (IP-üstü) bir tavan ekle.

### G5 — ORTA: `ability_search` global option'ı mutasyona uğratıyor
**Dosya:** `includes/class-abilities.php:127-135`

`max_results`'ı DB option'una yazıp aramadan sonra geri yazıyor. Eşzamanlı iki istek değeri kalıcı olarak bozabilir; ayrıca her aramada 2 gereksiz `update_option` (DB yazımı + autoload cache invalidation).

**Düzeltme:** `Search::search()`'e `$limit` parametresi ekle; option'a hiç dokunma.

### G6 — DÜŞÜK: Bilgi ifşası ve taşıma notları
- **Gemini API anahtarı URL query string'inde** (`class-ai-client.php:298`) — sunucu erişim loglarına düşebilir. Gemini API'nin gereği ama loglama riski not edilmeli.
- **SSRF yüzeyi:** `api_base_url` / `open_notebook_url` kısıtsız `wp_remote_*`'a gidiyor; yalnızca admin ayarlayabildiği için risk düşük.
- **`/test` debug çıktısı** `api_base_url` ve `api_key_length` döndürüyor (`class-rest-controller.php:108`) — admin-only, kabul edilebilir.

---

## 3. Mantık Hataları

### M1 — `smart_assistant_page_skipped` hiçbir yerde set edilmiyor
**Dosya:** `includes/helpers.php:180-186`

Migration bloğu `get_option( 'smart_assistant_page_skipped' )`'i okuyor ama kod tabanında bu option hiçbir yerde yazılmıyor. Sonuç:

1. Kullanıcı `page`'i bilinçli olarak post_types'tan kaldırsa bile **her sayfa yüklemesinde** migration onu geri ekliyor — `page` asla kaldırılamıyor.
2. Her istekte `update_option` çalışıyor (gereksiz DB yazımı).

**Düzeltme:** Migration'ı aktivasyona taşı ya da bir kez çalıştıktan sonra `smart_assistant_page_skipped` bayrağını yaz; kullanıcı `page`'i kaldırdığında bayrağı set et.

### M2 — `suggest_questions()` ölü kod
**Dosya:** `includes/class-ai-client.php:97-161`

~65 satırlık metot hiçbir yerden çağrılmıyor (grep ile doğrulandı). Summarize kendi delimiter-tabanlı öneri yöntemini kullanıyor. Bakım yükü.

**Düzeltme:** Sil ya da summarize akışına bağla.

### M3 — Admin i18n localize adı uyuşmuyor
**Dosya:** `admin/js/admin.js:115,138,173,175` vs `admin/class-admin.php:41`

admin.js sürekli `window.SmartAssistantI18n`'i okuyor ama PHP tarafı `SmartAssistantAdmin.i18n` olarak localize ediyor. `SmartAssistantI18n` hiç tanımlı değil → tüm çeviriler her zaman hardcoded fallback'e düşüyor.

**Düzeltme:** admin.js referanslarını `SmartAssistantAdmin.i18n` yap ya da PHP'de `SmartAssistantI18n` olarak da localize et.

### M4 — `extract_nonce` yorum/kod uyuşmazlığı
**Dosya:** `includes/class-rest-controller.php:355-359`

Yorum "Authorization: Bearer veya doğrudan" diyor ama kod yalnızca `nonce ` prefix'ini işliyor. Bearer desteği yok.

**Düzeltme:** Yorumu koda göre düzelt ya da beklenen Bearer davranışını ekle.

### M5 — Model varsayılanları doğrulanmalı
**Dosya:** `includes/helpers.php:152,155,205`

Default `provider => 'MiniMax'`, `model => 'MiniMax-M3'`; presets `MiniMax-M3/M2.5` listeliyor. Bu model kimlikleri gerçek MiniMax API adlarıyla uyuşmayabilir; ilk kurulumda test çağrısı hata verebilir.

**Düzeltme:** Geçerli model kimliklerini doğrula ve güncelle.

---

## 4. Performans / Kullanım Sorunları

- **P1 — `relevance_search` full table scan:** her kelime için `LOWER(post_content) LIKE '%...%'` index kullanamaz. Az yazı için tasarlanmış (Mod 1 böyle konumlanmış) ama üst sınır belirsiz; büyük sitede yavaşlar. (`class-search.php:224`)
- **P2 — `diversify_results` N+1:** her sonuç için `wp_get_post_terms()` + `get_post_field()`. SQL'den gelen `stdClass` sonuçlar cache'te olmadığı için ekstra sorgular. `update_post_caches` veya toplu term sorgusuyla azaltılabilir. (`class-search.php:305,315`)
- **P3 — `optimize_context` sabit bütçe:** 4000 token sabit kodlanmış, ayarlarla ilişkisiz. (`class-ai-client.php:478`)
- **P4 — `format_post` DOMDocument:** her sonuç için ayrı parse; toplu aramada maliyetli. (`class-search.php:436`)

---

## 5. İyi Yapılmış Yönler

- SQL injection'a karşı `$wpdb->prepare` + `esc_like` tutarlı kullanımı.
- Frontend markdown render'ında HTML escape + güvenli protokol whitelist'i (XSS savunması).
- API anahtarı kaydında maskeleme (`•` kontrolü) ve "boş bırakırsan değişmez" mantığı.
- Admin aksiyonlarında `check_admin_referer` (CSRF).
- Yayınlanmamış içerik için `is_post_readable` + `current_user_can('read_post')` kontrolü (parola durumu hariç — bkz. G2).
- Kapsamlı CI: syntax lint, PHPCS, PHPUnit (4 PHP sürümü), PHPStan level 5.

---

## 6. Gelişim Planı (öncelik sırasına göre)

### Faz 1 — Güvenlik (acil)
1. **G1:** `normalize_history`'de `system` rolünü reddet; system prompt'u yalnızca sunucu enjekte etsin.
2. **G2:** `is_post_readable` + iki arama SQL'i + `WP_Query` fallback'ine `post_password` filtresi ekle.
3. **G3:** `message` ve `history[].content` için uzunluk sınırı uygula.
4. **G5:** `ability_search`'ü `search($query, $post_id, $limit)` imzasıyla refactor et; global option mutasyonunu kaldır.
5. **G4:** Rate limit'i atomik hale getir; object-cache'siz davranışı netleştir.

### Faz 2 — Mantık hataları
6. **M1:** `page_skipped` migration'ını düzelt (aktivasyona taşı / bayrağı yaz).
7. **M2:** `suggest_questions()` ölü kodunu sil veya bağla.
8. **M3:** Admin i18n localize adını düzelt.
9. **M4:** `extract_nonce` yorum/kod tutarlılığı.
10. **M5:** Model varsayılanlarını doğrula.

### Faz 3 — Sağlamlaştırma ve test
11. `RestController` için PHPUnit testleri ekle (nonce, rate limit, history-injection, parola korumalı içerik senaryoları).
12. **P2/P4:** N+1 sorguları ve DOMDocument maliyetini azalt.
13. **P3:** `optimize_context` bütçesini ayarlanabilir yap.

### Faz 4 — İyileştirmeler
14. Prompt injection için ek savunma (kullanıcı mesajındaki "önceki talimatları unut" kalıplarını işaretle/logla).
15. Büyük siteler için Mod 1 aramasında FULLTEXT index yönlendirmesi/uyarısı.
