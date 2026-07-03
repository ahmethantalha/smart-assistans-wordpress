# Smart Assistant — Özellik Yol Haritası

**Tarih:** 2026-07-03 · **Mevcut sürüm:** 1.0.3
**Yöntem:** Kod tabanının tam taraması + sektör liderlerinin (Tidio/Lyro, Chatbase, DocsBot AI, Intercom Fin, AI Engine, Botpress) özellik setleriyle karşılaştırma.

---

## 1. Mevcut Durum Envanteri

### Sahip olduklarımız
| Alan | Özellik |
|---|---|
| Arama | Mod 1: Türkçe-optimize LIKE relevance araması · Mod 2: Open Notebook semantik arama |
| AI | 4 provider (MiniMax, OpenAI, Gemini, Anthropic), context window optimizasyonu, thinking/bozuk link temizliği |
| Widget | Balon + mini panel + kolon modu, makale özetleme (FAB), öneri sorular, mesaj kopyalama |
| Araçlar | Hesaplayıcı testler (TDEE, BMI, yağ oranı) + admin'den ekle/sil/düzenle |
| Kimlik | AI adı, ton, selamlama, few-shot örnekler, imza |
| Güvenlik | Nonce, IP rate limit (atomik), ezilemez güvenlik önsözü, PII redaksiyonu, injection loglama, parola korumalı içerik koruması |
| Entegrasyon | WP Abilities API (search_content, get_post), GitHub üzerinden otomatik güncelleme |
| Kalite | CI (lint, PHPCS, PHPStan L5, PHPUnit ×4 PHP sürümü) |

### Sektöre göre eksiklerimiz
Sektörde 2026 itibarıyla standart sayılan ve bizde olmayanlar:

| Eksik | Kimde var | Etki |
|---|---|---|
| Streaming yanıt | Hepsi | Algılanan hız — şu an 60 sn'ye kadar boş bekleme |
| Sohbet kalıcılığı | Hepsi | Sayfa geçişinde sohbet sıfırlanıyor |
| Analitik panosu | Chatbase, DocsBot, Fin | Site sahibi botun değerini göremiyor |
| Cevapsız soru raporu | Chatbase | İçerik açığı analizi = içerik stratejisi girdisi |
| Lead capture | Chatbase, Tidio | Ziyaretçiyi potansiyel müşteriye çevirme |
| Geri bildirim (👍/👎) | Hepsi | Cevap kalitesi ölçülemiyor |
| İnsana devir | DocsBot, Fin, Tidio | Bot çözemeyince çıkmaz sokak |
| Yerel embeddings (RAG) | Hepsi | LIKE araması anlam yakalayamıyor; ON harici bağımlılık |
| Görünüm özelleştirme | Hepsi | Renk/pozisyon/avatar ayarı yok |
| WooCommerce | Tidio, AI Engine | E-ticaret sitelerinde ürün soruları |
| Maliyet/token takibi | AI Engine | API faturası görünmez |
| GDPR paketi | Hepsi (zorunlu) | AB pazarı için ön şart |

---

## 2. Yol Haritası

Sıralama ölçütü: **kullanıcı etkisi ÷ efor**. Her faz kendi başına yayınlanabilir bir sürüm.

### Faz A — Temel Deneyim (v1.1) · ✅ TAMAMLANDI (2026-07-03)
Hedef: widget'ı sektör standardı hissiyatına getirmek.

| # | Özellik | Detay | Durum |
|---|---|---|---|
| A1 | **Streaming yanıt (SSE)** | `stream: true` + `fetch` ReadableStream ile kelime kelime yazım. 3 provider (OpenAI-uyumlu, Anthropic, Gemini) SSE parser'ı; holdback'li akış redaksiyonu (PII akışa sızmaz); desteklenmeyen ortamda otomatik normal moda düşme. | ✅ |
| A2 | **Sohbet kalıcılığı** | `sessionStorage` ile sekme ömrü boyunca sohbet + geçmiş + aktif araç + açık/kapalı durumu korunur. Ayarlardan kapatılabilir. | ✅ |
| A3 | **Geri bildirim** | Her AI mesajına 👍/👎; `/feedback` endpoint'i (nonce + rate limit); sayaçlar + son 50 olumsuz mesaj (içerik açığı analizi için) saklanır; admin Bilgi kartında özet. | ✅ |
| A4 | **Görünüm özelleştirme** | Ana renk (CSS custom properties), balon pozisyonu (sağ/sol), launcher emoji'si, karşılama balonu gecikmesi (0=kapalı). Ayrıca settings alanlarının tümünün tek section'a kaydolması bug'ı düzeltildi. | ✅ |
| A5 | **Shortcode + Gutenberg block** | `[smart_assistant]` shortcode'u + "Smart Assistant Sohbet" dynamic block'u; panel sayfa içine gömülü modda açılır. | ✅ |

### Faz B — Site Sahibi Değeri (v1.2) · ~2-3 hafta efor
Hedef: botu "maliyet kalemi"nden "değer üreten araç"a çevirmek.

| # | Özellik | Detay | Efor |
|---|---|---|---|
| B1 | **Konuşma kayıtları** | Opsiyonel `wp_sa_conversations` tablosu; admin'de listeleme/görüntüleme. KVKK/GDPR: IP anonimleştirme + saklama süresi ayarı. | Orta |
| B2 | **Analitik panosu** | Günlük soru sayısı, benzersiz kullanıcı, ort. mesaj/oturum, 👍/👎 oranı, token kullanımı & tahmini maliyet. | Orta |
| B3 | **Cevapsız sorular raporu** | "Kaynak bulunamadı" ile biten sorguları topla → içerik açığı listesi. Chatbase'in en çok övülen özelliği; blog için doğrudan içerik fikri üretir. | Düşük |
| B4 | **Lead capture** | Bot cevap veremeyince veya N mesaj sonra opsiyonel ad+e-posta formu; admin'e e-posta bildirimi + kayıt listesi. | Orta |
| B5 | **Token bütçesi** | Günlük/aylık токен tavanı; aşılınca bot kibarca kapanır. Maliyet sürprizini önler. | Düşük |

### Faz C — Cevap Kalitesi: RAG Yükseltmesi (v1.3) · ~3-4 hafta efor
Hedef: LIKE aramasından gerçek anlamsal aramaya geçiş (harici Open Notebook bağımlılığı olmadan).

| # | Özellik | Detay | Efor |
|---|---|---|---|
| C1 | **Yerel embeddings** | Yazıları chunk'lara böl, seçili provider'ın embedding API'siyle vektörle, `wp_sa_embeddings` tablosunda sakla, cosine similarity ile ara. Mod 1.5: ON kurmadan semantik arama. | Yüksek |
| C2 | **Otomatik indeksleme** | `save_post` hook'u + arka planda (Action Scheduler / WP-Cron) toplu indeksleme; admin'de ilerleme çubuğu. | Orta |
| C3 | **Kaynak atıfları** | Cevap içinde [1][2] numaralı atıflar; kaynak kartlarıyla eşleşme. | Düşük |
| C4 | **Çok dillilik** | Kullanıcının dilini algıla, aynı dilde yanıtla; Polylang/WPML dil filtresi ile arama. | Orta |
| C5 | **WooCommerce** | Ürün arama (başlık/açıklama/fiyat/stok), ürün kartı render'ı, "sepete ekle" linki. E-ticaret pazarına açılım. | Orta |

### Faz D — Kurumsal & Büyüme (v2.0) · ~4+ hafta efor

| # | Özellik | Detay | Efor |
|---|---|---|---|
| D1 | **İnsana devir** | Bot çözemeyince "temsilciye yaz" → e-posta ile tam transkript + iletişim bilgisi (Fin'in "start over yok" yaklaşımı). İleride canlı sohbet köprüsü. | Orta |
| D2 | **Proaktif mesajlar** | Sayfa/kategori bazlı tetikleyiciler: X sn sonra, scroll %70'te, exit-intent'te özel mesaj. | Orta |
| D3 | **GDPR/KVKK paketi** | Sohbet öncesi onay metni, veri saklama süresi, "verilerimi sil" akışı, kayıtlarda PII anonimleştirme. AB pazarı için zorunlu. | Orta |
| D4 | **Çoklu bot profili** | Sayfa/kategori başına farklı kimlik + prompt + araç seti (ör. destek sayfasında destek botu, blogda içerik botu). | Yüksek |
| D5 | **Webhook & REST API** | Sohbet olayları için webhook (lead geldi, cevapsız soru); dış sistem (CRM, Slack) entegrasyonu. | Orta |
| D6 | **A/B prompt testi** | İki system prompt varyantını trafiğe böl, 👍/👎 oranıyla karşılaştır. | Düşük |

---

## 3. Önerilen Sıra ve Gerekçe

1. **A1 (streaming) + A2 (kalıcılık)** — kullanıcının her sohbette hissettiği iki eksik; rakiplerle aradaki en görünür fark.
2. **A3 + B3 (geri bildirim + cevapsız sorular)** — ölçüm olmadan diğer yatırımların değeri kanıtlanamaz; ikisi de düşük efor.
3. **B2 + B5 (analitik + bütçe)** — site sahibinin güvenini kazanır: "bot ne yapıyor, bana kaça mal oluyor".
4. **C1-C2 (yerel RAG)** — cevap kalitesinde sıçrama; ON bağımlılığını opsiyonel hale getirir ve eklentiyi kendi başına rekabetçi yapar.
5. **B4 + D1 (lead + devir)** — botu gelir üreten kanala çevirir.

## 4. Teknik Ön Koşullar (fazlardan önce)

- [ ] `docs/CODE-REVIEW.md`'de açık kalan **M5** (MiniMax model kimlikleri doğrulaması).
- [ ] RestController için PHPUnit testleri (nonce, rate limit, history injection) — yeni özellikler regresyonsuz eklensin diye.
- [ ] DB tablosu altyapısı (Faz B/C tabloları için ortak migration/upgrade mekanizması, `dbDelta` + sürüm option'ı).
- [ ] Streaming için `admin-ajax`/REST yerine SSE uyumluluğu araştırması (bazı hosting'lerde output buffering sorunları).
