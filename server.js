import express from "express";
import fetch from "node-fetch";
import cors from "cors";
import path from "path";
import { fileURLToPath } from "url";
import { dirname } from "path";

const app = express();
app.use(cors());

// 📁 Map instellingen
const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// 👉 vervang dit straks door je echte ElevenLabs API-key
const API_KEY = "sk_f25ed488961828aa07748dd10eaab87bdbf99cc360e1573b";
const VOICE_ID = "2EiwWnXFnvU5JabPnv8n"; // Clyde

// 📄 Statische bestanden (zoals jouw 11labs.html)
app.use(express.static(__dirname));

// 🌍 Startpagina
app.get("/", (req, res) => {
  res.sendFile(path.join(__dirname, "11labs.html"));
});

// 🎙️ TTS-route
app.get("/tts", async (req, res) => {
  const text = req.query.text || "Welkom bij CityTour Amsterdam!";
  console.log("Ontvangen tekst:", text);

  try {
    const response = await fetch(`https://api.elevenlabs.io/v1/text-to-speech/${VOICE_ID}`, {
      method: "POST",
      headers: {
        "xi-api-key": API_KEY,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        text,
        model_id: "eleven_multilingual_v2",
        voice_settings: { stability: 0.4, similarity_boost: 0.9 },
      }),
    });

    if (!response.ok) {
      const errText = await response.text();
      console.error("Fout van ElevenLabs:", errText);
      return res.status(500).send("Fout bij ophalen audio.");
    }

    const buffer = Buffer.from(await response.arrayBuffer());
    res.set("Content-Type", "audio/mpeg");
    res.send(buffer);
  } catch (error) {
    console.error("Serverfout:", error);
    res.status(500).send("Interne serverfout.");
  }
});

// 🚀 Poort voor Render
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`✅ Server draait op poort ${PORT}`));
