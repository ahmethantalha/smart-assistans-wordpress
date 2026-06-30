# Smart Assistant

WordPress için AI destekli site asistanı. **MiniMax API** ile sitenizin içeriklerinden hareketle cevap verir, kaynak yazılara yönlendirir. Sağ alt köşede chatbot widget + her yazıda "Özetle" butonu.

> **Durum:** v1.0.0 — Mod 1 (basit) ve çoklu LLM sağlayıcı desteği (MiniMax, OpenAI uyumlu, Gemini, Anthropic) stabil. Mod 2 (Open Notebook) entegrasyonu temel seviyede.

---

## ✨ Özellikler

- 🤖 **Sağ alt köşe chatbot widget** — tüm sayfalarda, açılır/kapanır panel
- ✨ **"Özetle" floating buton** — yazı detayında scroll ile takip eder
- 🔄 **Genişlet akışı** — mini sohbetten sağdaki sütuna, sonra tekrar küçültebilirsiniz
- 🧠 **MiniMax API entegrasyonu** — OpenAI uyumlu herhangi bir API ile çalışır
- 📚 **Kaynak linkleri** — her cevapta ilgili yazılara [1], [2] atıfları
- 💾 **Sıfır kalıcılık** — sohbetler RAM'de; cache temizlenince veya "Sil" deyince sıfırlanır
- 🛡 **Güvenlik** — nonce, rate limit (IP başına dakikada X istek), capability check
- 🧩 **Abilities API** (WP 7.0) — dış AI agent'lar (Claude Desktop vs.) sizinle konuşabilsin
- 🔌 **İki mod** — basit WP araması (sıfır kurulum) veya Open Notebook (semantik)

---

## 📦 Kurulum

### 1. Zip olarak yükle

```bash
cd /workspace
zip -r smart-assistant.zip smart-assistant/
```

WP Admin → **Eklentiler → Yeni Ekle → Eklenti Yükle → smart-assistant.zip** → Etkinleştir.

### 2. MiniMax API anahtarı al

- https://platform.MiniMax.io/ → hesap → API key oluştur
- Bu anahtarı **Ayarlar → Smart Assistant → MiniMax API Anahtarı**'na girin

### 3. (Mod 2 için) Open Notebook kur

Open Notebook, NotebookLLM'in açık kaynak, self-hosted alternatifi. Docker ile:

```yaml
# docker-compose.yml
services:
  open-notebook:
    image: lfnovo/open-notebook:latest
    ports:
      - "8501:8501"
      - "5055:5055"
    environment:
      - OPENAI_API_KEY=...   # OpenAI uyumlu herhangi bir API
    volumes:
      - ./notebook-data:/app/data
```

```bash
docker-compose up -d
```

WordPress ayarlarında Open Notebook URL'sini `http://localhost:8501` olarak girin, **Mod** = `open_notebook` seçin.

### 4. Senkronize et (Mod 2)

Ayarlar sayfasında **"Tüm Yazıları Şimdi Senkronize Et"** butonu ile tüm post'ları Open Notebook'e yollayın. Sonraki yeni yazılar için n8n workflow'u veya WP cron kurabilirsiniz.

---

## 🎯 Kullanım

### Ziyaretçi tarafı

- **Her sayfada** sağ altta 💬 balonu → tıkla → sor
- **Yazı detayında** scroll ile sağda ✨ **"Bu yazıyı özetle"** butonu belirir
  - Tıklayınca mini pencere açılır, otomatik özetler
  - Soru sorabilirsiniz, sohbet devam eder
  - "Genişlet ↗" → sağdaki sütuna geçer, tüm site görünür kalır
  - "Küçült ↙" → tekrar mini pencereye
- **🗑 Sil** butonu sohbeti sıfırlar

### Admin tarafı

- **Ayarlar → Smart Assistant**:
  - Mod seçimi (basit / open_notebook)
  - API key, model adı, base URL
  - Sistem prompt'u (kendi karakterinizi verin)
  - Temperature, max tokens
  - Hangi post type'lardan arama yapılacak
  - Rate limit, Open Notebook URL

### Geliştirici tarafı — Abilities API

Mod 2 + Abilities açıkken, dış AI agent'lar şu yetenekleri kullanabilir:

```
smart-assistant/search_content  → query, limit
smart-assistant/get_post        → post_id
```

Örnek olarak Claude Desktop, Open Notebook + MCP üzerinden sitenize bağlanıp "şu konuda sitede ne yazıyor?" diye sorabilir.

---

## 🏗 Mimari

```
smart-assistant/
├── smart-assistant.php           # Plugin header + autoloader + bootstrap
├── uninstall.php                 # Temizlik
├── includes/
│   ├── class-plugin.php          # Singleton
│   ├── class-activator.php       # Default options
│   ├── class-deactivator.php
│   ├── class-settings.php        # WP Settings API
│   ├── class-ai-client.php       # MiniMax API istemcisi
│   ├── class-search.php          # Mod 1: WP FULLTEXT araması
│   ├── class-open-notebook.php   # Mod 2: Open Notebook HTTP istemcisi
│   ├── class-rest-controller.php # /chat, /summarize endpoint'leri
│   ├── class-abilities.php       # WP 7.0 Abilities
│   ├── class-loader.php
│   └── helpers.php               # Yardımcılar
├── admin/
│   ├── class-admin.php
│   └── views/settings-page.php
├── public/
│   ├── class-frontend.php        # Asset loader
│   ├── js/widget.js              # Chatbot + FAB
│   └── css/widget.css
│   └── css/fab.css
├── blocks/                       # (İleride) PHP-only block'lar
└── languages/                    # i18n
```

### Veri akışı (Mod 1)

```
Ziyaretçi soru sorar
   ↓
JS → POST /wp-json/smart-assistant/v1/chat
   ↓
RestController::handle_chat
   ├─ nonce doğrula, rate limit kontrol
   ├─ Search::search(query) → top 5 yazı
   ├─ AIClient::chat(messages, sources) → MiniMax API
   │     └─ system prompt + son user mesajına kaynakları enjekte et
   └─ { reply, sources } döndür
   ↓
JS → cevabı render + kaynak linkleri
```

### Veri akışı (Mod 2)

```
Ziyaretçi soru sorar
   ↓
JS → POST /wp-json/smart-assistant/v1/chat
   ↓
RestController::handle_chat
   ├─ OpenNotebook::ask(query) → POST http://on:8501/api/chat
   │     └─ ON en iyi chunk'ları bulur + LLM cevap üretir
   └─ { reply, sources } döndür
```

---

## 💰 Maliyet karşılaştırması

| | Mod 1 (Basit) | Mod 2 (Open Notebook) |
|---|---|---|
| Ek altyapı | Yok | Docker'da Open Notebook |
| Vektör DB | Yok | Open Notebook yönetir |
| API maliyeti | MiniMax token kullanımı | MiniMax + (ON için OpenAI uyumlu API) |
| Cevap kalitesi | İyi (keyword) | Çok iyi (semantik) |
| Kurulum zorluğu | 5 dk | 30 dk |

---

## 🔒 Güvenlik notları

- Tüm REST endpoint'ler public; nonce ile korunuyor
- IP başına dakikada `rate_limit_per_min` istek (default 20)
- API anahtarı admin dışında hiçbir yerde görünmez
- Çıktılar escape edilir, linklerde `rel="noopener noreferrer"`
- Sohbet geçmişi **asla sunucuda saklanmaz** — kullanıcı isteği

---

## 🛠 Geliştirme

### Lokal test

```bash
cd smart-assistant
php -l smart-assistant.php
node --check public/js/widget.js
```

### Yeni bir Mod eklemek

1. `includes/class-X.php` oluştur
2. `Plugin::instance()` içinde property ekle ve construct'ta örnekle
3. `boot()` içinde `register_hooks()` çağır
4. `smart-assistant.php` autoloader map'e ekle
5. Modu `smart_assistant_get_options()` defaults'a ekle
6. `Settings` sayfasına UI alanı ekle

### Yeni sürüm yayınlama (GitHub Release)

Eklenti, kurulu olduğu WordPress sitelerine GitHub Releases üzerinden otomatik güncelleme bildirimi gösterir ([plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) kütüphanesi ile, `includes/libs/` altında gömülü). Bunun çalışması için her sürümde:

1. `smart-assistant.php` üst bilgisindeki `Version:` ve `SMART_ASSISTANT_VERSION` sabitini, ayrıca `tests/bootstrap.php`'deki aynı sabiti güncelleyin.
2. Eklenti klasörünü **`smart-assistant/`** adıyla zip'leyin (klasör adı bu olmalı, repo adıyla karışmamalı):
   ```bash
   zip -r smart-assistant-X.Y.Z.zip smart-assistant/ -x "*.git*" "vendor/*" "tests/*" "node_modules/*"
   ```
3. GitHub'da `vX.Y.Z` formatında bir tag ile Release oluşturun (`master` branch üzerinden) ve bu zip dosyasını **Release asset'i** olarak ekleyin.
   - ⚠️ GitHub'ın otomatik oluşturduğu "Source code (zip)" kullanılmaz — onun içindeki klasör adı repo adına göre olduğundan (`smart-assistans-wordpress-X.Y.Z`), WordPress güncellemeyi yanlış klasöre kurar ve ayarlar kaybolabilir. Mutlaka adım 2'deki, doğru klasör adına sahip zip'i asset olarak yükleyin.
4. Yayınlandıktan sonra kurulu sitelerde Eklentiler sayfasında güncelleme bildirimi 12 saat içinde (veya **Güncellemeleri Denetle** ile anında) görünür.

---

## 📝 Lisans

GPL2+ — WordPress ile uyumlu.