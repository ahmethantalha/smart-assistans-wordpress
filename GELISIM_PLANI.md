# Smart Assistant — Proje İncelemesi ve Gelişim Planı

> Hazırlanma tarihi: 2026-06-29
> İncelenen sürüm: v0.4.1
> Kapsam: `includes/`, `admin/`, `public/`, kök dosyalar (toplam ~3.200 satır PHP + ~765 satır JS)

Bu belge, eklentinin mevcut durumunun teknik incelemesini ve önceliklendirilmiş bir
geliştirme yol haritasını içerir. Maddeler **önem ve risk sırasına** göre fazlara ayrılmıştır.

---

## 1. Genel Değerlendirme

Eklenti olgun bir MVP. Mimari temiz: singleton `Plugin`, PSR-4 benzeri autoloader,
sorumlulukların sınıflara ayrılması (Search / AIClient / OpenNotebook / RestController /
Abilities) iyi düşünülmüş. İki çalışma modu (basit WP araması / Open Notebook semantik),
Türkçe'ye özel relevance scoring, context window optimizasyonu ve WP 7.0 Abilities API
entegrasyonu artı puanlar.

Buna karşılık **güvenlik, çok-sağlayıcı (multi-provider) doğruluğu, maliyet kontrolü ve
test/CI altyapısı** alanlarında ciddi boşluklar var. Aşağıda hepsi detaylandırılmıştır.

**Güçlü yönler**
- Temiz katmanlı mimari, tek sorumluluk ilkesine yakın sınıflar.
- Türkçe stop-word temizliği + prefix tabanlı relevance scoring (FULLTEXT'in Türkçe
  zayıflığını telafi ediyor).
- Mod 2 hata verince sessizce Mod 1'e düşen graceful fallback.
- Kaynak çeşitlendirme (`diversify_results`) ile kategori/yazar tekrarını önleme.
- Context optimizasyonu (sliding window + eski mesaj özeti) LLM çağrısı yapmadan token tasarrufu.

---

## 2. Kritik Bulgular (Faz 0 — acil)

### 2.1 🔴 Taslak/özel yazı içeriği sızıntısı (güvenlik)
`RestController::handle_summarize()` → `Search::get_post($post_id)` → `get_post()` çağrısı
**post_status kontrolü yapmıyor**. `/summarize` endpoint'i public (`__return_true`).
Saldırgan `post_id` deneyerek **yayınlanmamış (draft), özel (private) veya parola korumalı**
yazıların tam içeriğini AI özeti olarak çekebilir.

**Çözüm:** `get_post()` ve tek-post bağlamlı `search()` dallarında
`'publish' === get_post_status()` (ve gerekiyorsa post_type whitelist) zorunlu kılınmalı.
Özel içerik için en azından `current_user_can('read_post', $id)` kontrolü.

### 2.2 🔴 Nonce doğrulaması fiilen devre dışı
`preflight()` geçersiz nonce'ı yalnızca **loglayıp devam ediyor**. Tek koruma IP rate-limit.
Bu, `/chat` ve `/summarize` üzerinden **maliyetli LLM çağrılarının** kötüye kullanılmasına
açık kapı bırakıyor (API faturası saldırısı / abuse).

**Çözüm:** Nonce geçerliyse kabul; geçersizse reddet. CDN/cache senaryosu için ayrı bir
hafif token mekanizması (kısa ömürlü, sayfa yüklemesinde basılan) düşünülebilir, ama
"her durumda devam et" kaldırılmalı.

### 2.3 🔴 Gemini & Anthropic sağlayıcıları gerçekte çalışmıyor
`smart_assistant_get_provider_presets()` dört sağlayıcı tanımlıyor ve her birine `auth`
tipi veriyor (`bearer` / `query` / `x-api-key`). Ancak `AIClient::chat()` **her zaman**
`Authorization: Bearer ...` header'ı gönderiyor ve endpoint olarak `/chat/completions`
ekliyor. Sonuç:
- **Gemini** (`auth: query`, native API `/chat/completions` yok) → çalışmaz.
- **Anthropic** (`auth: x-api-key`, farklı body şeması) → çalışmaz.
- Ayarlardaki `group_id` (MiniMax Token Plan) **hiçbir isteğe eklenmiyor** → ölü konfig.

**Çözüm:** Provider'a göre auth header'ı, endpoint'i ve gerekiyorsa body dönüşümünü
soyutlayan bir provider katmanı; ya da UI'da yalnızca gerçekten desteklenenleri (OpenAI
uyumlu) bırakıp gerisini "yakında" olarak işaretlemek.

### 2.4 🟠 Activator'daki varsayılan API URL'si bozuk
`Activator::activate()` `'api_base_url' => 'https://api.MiniMax.chat/v1'` yazıyor; bu URL
zaten `helpers.php` içindeki migration'ın düzelttiği **bozuk** adres. Yeni kurulumlar
gereksiz yere migration'a muhtaç doğuyor.

**Çözüm:** Activator defaults'u `https://api.minimax.io/v1` ve model değerleri
`smart_assistant_get_options()` defaults'u ile birebir aynı olmalı (tek kaynak: helpers).

---

## 3. Doğruluk & Sağlamlık (Faz 1)

### 3.1 Ölü/eski kod
- `Search::has_fulltext()` ve `Search::is_mysql_compatible_query()` tanımlı ama **hiç
  çağrılmıyor** (relevance_search LIKE kullanıyor). Kaldırılmalı veya gerçekten kullanılmalı.
- `get_page_by_title()` (open-notebook `enrich_sources_with_wp_urls`) **WP 6.2'den beri
  deprecated**. `WP_Query` ile `title` araması veya `get_posts` kullanılmalı.

### 3.2 Bozuk HTML link "whack-a-mole"
`AIClient::strip_broken_links()` 4 ayrı regex ile AI'nin ürettiği bozuk `<a>` kalıntılarını
temizlemeye çalışıyor (Pattern 3 ve 4 birebir aynı — kopya). Kök neden: kaynaklar AI'a
**ham metin** olarak enjekte ediliyor ve içlerinde HTML kalıntısı olabiliyor.

**Çözüm:** Kaynakları enjekte etmeden önce `extract_plain_text` çıktısının URL/attribute
kalıntısından tamamen arındırıldığından emin olmak; AI'a HTML değil yalnızca başlık + temiz
metin + URL vermek. Böylece çıktı temizliği kırılgan regex yerine girdi temizliğiyle çözülür.

### 3.3 Abilities arama global option'ı mutasyona uğratıyor
`Abilities::ability_search()` her çağrıda `max_results`'ı DB'deki option'a yazıp sonra geri
yazıyor. Bu hem **gereksiz DB write** hem de eşzamanlı isteklerde **race condition**.

**Çözüm:** `Search::search()`'e opsiyonel `$limit` parametresi eklemek; option'ı hiç
değiştirmemek.

### 3.4 Prompt enjeksiyonu çok agresif ve token-ağır
`inject_sources()` her istekte "!!! KESIN KURALLAR !!!" bloğunu son user mesajına ekliyor.
Hem token maliyeti yüksek hem de model davranışı kırılgan (büyük-harf komutlara aşırı bağımlı).

**Çözüm:** Kuralları system prompt'ta bir kez tanımlamak, kaynakları kısa ve yapısal
(JSON-benzeri) vermek; tekrarları azaltmak.

### 3.5 Hata yanıtı tutarsızlığı
`error_response()` HTTP 400 dönerken, rate-limit 429 dönüyor; `handle_test` ise hataları 200
gövdesinde `ok:false` ile dönüyor. İstemci tarafında tutarlı hata sözleşmesi yok.

---

## 4. Güvenlik & Maliyet Kontrolü (Faz 1–2)

### 4.1 API anahtarı düz metin
Anahtar `wp_options` içinde **şifresiz** duruyor. WP'de tam güvenli saklama zor olsa da, en
azından `wp-config.php` sabiti (`SMART_ASSISTANT_API_KEY`) ile override + DB'de maskeleme
seçeneği sunulmalı. UI maskelemesi (••••) zaten var, iyi.

### 4.2 IP tabanlı rate-limit zayıf
- `get_client_ip()` yalnızca `REMOTE_ADDR` kullanıyor → reverse-proxy/CDN arkasında **tüm
  kullanıcılar tek IP** olarak görünebilir (herkes birbirini kilitler) veya saldırgan
  `X-Forwarded-For` ile kaçabilir.
- Transient tabanlı sayaç object-cache yokken atomik değil.
- **Global bütçe yok**: günlük/aylık toplam istek veya token tavanı tanımlanamıyor.

**Çözüm:** Güvenilir proxy listesiyle IP çözümleme; opsiyonel global günlük token/istek
bütçesi; aşımda kibar "yoğunluk" mesajı.

### 4.3 Yanıt önbelleği yok
Aynı/benzer sorular her seferinde LLM'e gidiyor. Sık sorulan sorular için **transient
tabanlı cache** (sorgu hash'i → cevap, kısa TTL) hem maliyet hem gecikme kazandırır.

### 4.4 Token/maliyet telemetri yok
`usage` API'den dönüyor ama hiçbir yerde toplanmıyor. Admin'e **günlük token/çağrı/tahmini
maliyet** paneli değer katar (ve bütçe uyarısı tetikler).

---

## 5. Test, Kalite & Süreç Altyapısı (Faz 1)

Şu an **hiç test, CI, kodlama standardı veya bağımlılık yönetimi yok**.

- **`composer.json`** ekle: `wp-coding-standards/wpcs` (PHPCS), `phpstan/phpstan` + WP stub'ları.
- **PHPUnit + WP test harness**: en azından Search relevance, context optimizasyonu,
  strip fonksiyonları, sanitize için birim testleri.
- **GitHub Actions CI**: `php -l` lint, PHPCS, PHPStan, PHPUnit; `node --check` widget.js.
- **i18n**: `text-domain` yükleniyor ama `/languages` klasörü ve `.pot` dosyası yok
  (README'de vaat ediliyor). `wp i18n make-pot` ile `.pot` üretip CI'ya bağla.
- **`readme.txt`** (WordPress.org formatı) — dağıtım hedefleniyorsa gerekli; şu an yalnızca
  `README.md` var.

---

## 6. Özellik & UX İyileştirmeleri (Faz 2–3)

- **Streaming yanıt**: Kod `'stream' => false` sabit. SSE/streaming ile algılanan hız ciddi
  artar; widget zaten "Düşünüyor…" durumunu yönetiyor, parça parça render'a uygun.
- **Konuşma analitiği (opt-in)**: "Sıfır kalıcılık" varsayılan kalsın, ama opsiyonel olarak
  anonim soru logu (cevap kalitesini iyileştirme + içerik boşluğu tespiti) sunulabilir.
- **İçerik boşluğu raporu**: "Kaynak bulunamadı" dönen sorguları toplayıp editöre "bu
  konularda yazı eksik" raporu çıkarmak — SEO/içerik stratejisi için güçlü bir kanca.
- **FAB/widget erişilebilirliği**: ARIA rolleri, klavye navigasyonu, focus-trap, mobil
  responsive denetimi (765 satırlık vanilla JS gözden geçirilmeli).
- **Gutenberg block / shortcode**: Asistanı belirli bir sayfaya gömme seçeneği
  (`blocks/` klasörü README'de var ama boş).
- **Çoklu dil**: Sabit Türkçe metinler (`'Düşünüyor…'`, stop-word listesi) dil-bağımsız
  hale getirilebilir; stop-word listesi locale'e göre yüklenebilir.
- **Open Notebook senkron otomasyonu**: Şu an "Tümünü Senkronize Et" manuel buton + cron
  öneriliyor. `save_post`/`trash_post` hook'larıyla otomatik incremental sync eklenebilir.

---

## 7. Önerilen Yol Haritası (özet)

| Faz | Başlık | İçerik | Tahmini efor |
|-----|--------|--------|--------------|
| **0** | Acil düzeltmeler | 2.1 taslak sızıntısı, 2.2 nonce, 2.4 activator URL | 1–2 gün |
| **1a** | Doğruluk | 2.3 provider auth, 3.1 ölü kod, 3.3 abilities, 3.2 link temizliği | 3–5 gün |
| **1b** | Altyapı | composer + PHPCS + PHPStan + PHPUnit + CI + i18n .pot | 3–5 gün |
| **2** | Maliyet & güvenlik | 4.1 key sabiti, 4.2 rate-limit, 4.3 cache, 4.4 telemetri | 4–6 gün |
| **3** | UX & büyüme | streaming, içerik boşluğu raporu, blok, erişilebilirlik, oto-sync | 1–2 hafta |

> **Önce Faz 0** önerilir: güvenlik açığı (2.1) ve nonce (2.2) canlı sitelerde gerçek risk.

---

## 8. Uygulama Durumu

**Faz 0 + hızlı kazanımlar tamamlandı (v0.4.2):**

- [x] **2.1** Taslak/özel yazı sızıntısı kapatıldı — `Search::is_post_readable()`
      yardımcısı; `get_post()` ve tek-post arama dalı artık yalnızca `publish` veya
      `current_user_can('read_post')` geçen içeriği döndürüyor.
- [x] **2.2** Nonce zorunlu kılındı — geçersiz/eksik nonce 403 ile reddediliyor.
      Cache-ağırlıklı siteler için `smart_assistant_enforce_nonce` filter escape-hatch'i.
- [x] **2.4** Activator varsayılan API URL'si `https://api.minimax.io/v1` olarak düzeltildi.
- [x] Ölü kod silindi (`has_fulltext`, `is_mysql_compatible_query`).
- [x] `strip_broken_links` içindeki yinelenen regex tekilleştirildi.
- [x] Deprecated `get_page_by_title` → `WP_Query` (`title` parametresi).

**Sıradaki:** Faz 1a (2.3 provider auth katmanı, 3.3 abilities option mutasyonu) ve
Faz 1b (composer + PHPCS/PHPStan + PHPUnit + CI + i18n).

## 9. Hızlı Kazanımlar (yarım günlük işler)

1. Activator varsayılan URL'sini düzelt (2.4).
2. `get_post()`/`get_post_status()` publish kontrolü (2.1).
3. `has_fulltext` / `is_mysql_compatible_query` ölü kodunu sil (3.1).
4. `strip_broken_links` içindeki yinelenen Pattern 3/4 regex'ini tekille (3.2).
5. `get_page_by_title` → `WP_Query` (deprecation, 3.1).
6. `.gitignore` + `composer.json` iskeleti + temel GitHub Actions lint workflow'u.
