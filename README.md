# Semitexa Framework

### Bridging the gap between Architectural Complexity and Economic Efficiency.

The Semitexa framework is a response to the systemic challenges of modern PHP development. Our mission is to reclaim the original economic advantage of PHP — **affordability and rapid iteration** — without compromising on enterprise-grade quality.

---

## 🚀 Beyond the "Born to Die" Paradigm
For decades, PHP has operated on a stateless model where every request starts and ends the process. Semitexa moves past this physical limit by leveraging **Swoole**.

* **Persistent Memory Model:** Transitioning from stateless execution to a persistent runtime.
* **Massive Concurrency:** Handling scaling as a core internal function rather than an external infrastructure burden.

## 🤖 AI-Native Engineering
The emergence of AI Agents has changed the software development lifecycle. Semitexa is engineered to be **AI-oriented from the ground up**:
* **Hallucination-Free:** Strict structural constraints and explicit typing ensure LLMs can navigate the codebase with maximum precision.
* **Predictable Patterns:** Deterministic discovery patterns allow AI agents to generate and maintain code with minimal errors.

## 📉 Solving "The Elegance Paradox"
Drawing from experience with ecosystems like **Magento 2**, Semitexa addresses the friction where "clean architecture" is perceived as unnecessary overhead. We align technical excellence with business efficiency, making high-level architectural patterns accessible and cost-effective.

---

## 🚀 Quick Start

No PHP, Composer, or extensions required on the host — only Docker.

```sh
mkdir my-project && cd my-project
docker run --rm -v $(pwd):/app semitexa/installer install
docker compose up -d
```

That's it. On first boot the `setup` container runs `composer create-project` automatically.
The app is available at **http://localhost:8080** once it's ready.

### After first boot

```sh
# Run database migrations
./bin/semitexa php bin/semitexa db:migrate

# Open a shell inside the container
./bin/semitexa sh

# Run any framework CLI command
./bin/semitexa php bin/semitexa <command>
```

### How it works

| Step | What happens |
|------|-------------|
| `docker run semitexa/installer install` | Writes `Dockerfile`, `docker-compose.yml`, `.env` (with generated secrets), `bin/semitexa` into the current directory |
| `docker compose up -d` | Starts `db` + `redis` → `setup` runs `composer create-project semitexa/ultimate` into a named volume → `app` starts Swoole on `:8080` |
| Subsequent restarts | `setup` detects `vendor/autoload.php` and exits instantly — no reinstall |

---

## 🏛️ Heritage & Open Source
Semitexa is built upon the collective wisdom of the PHP community, reflecting lessons learned from:
**Zend Framework • Symfony • Laravel • Laminas • Magento 2**

We don't seek to replace these tools but to offer a specialized alternative for next-generation challenges.

---

### 🔗 Resources
* **Official Website:** [semitexa.com](https://semitexa.com)
* **Documentation:** [Read the Docs](https://semitexa.com/docs)
* **AI-Optimized Guide:** [Follow the Guide](https://semitexa.com/ai-guide)

---
*At its heart, Semitexa is a commitment to the Open Source ecosystem and the continuous evolution of professional PHP.*