// ------------------------------
// CityTour Amsterdam - TTS Server
// Klaar voor localhost én Render
// ------------------------------
import express from "express";
import cors from "cors";
import dotenv from "dotenv";
import fetch from "node-fetch";
import path from "path";
import { fileURLToPath } from "url";

dotenv.config();
const app = express();
app.use(cors());
app.use(express.json());

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// ------------------------------
// API KEYS
// ------------------------------
const API_KEY = process.env.ELEVENLABS_API_KEY;
const VOICE_ID = process.env.ELEVENLABS_VOICE_ID || "EXAVITQu4vr4xnSDxMaL";

// ------------------------------
// ROOT → 11labs testpagina
// ------------------------------
app.get("/", (req, res) => {
  res.sendFile(path.join(__dirname, "11labs.html"));
});

// ------------------------------
// OUDE TEST-ROUTE (mag blijven)
// ------------------------------
app.post("/tts", async (req, res) => {
  const text = req.body.text || "Hallo Bart";

  try {
    const response = await fetch(
      `https://api.elevenlabs.io/v1/text-to-speech/${VOICE_ID}`,
      {
        method: "POST",
        headers: {
          "xi-api-key": API_KEY,
          "Content-Type": "application/json",
          "Accept": "audio/mpeg"
        },
        body: JSON.stringify({
          text,
          model_id: "eleven_multilingual_v2",
        }),
      }
    );

    const buffer = Buffer.from(await response.arrayBuffer());
    res.setHeader("Content-Type", "audio/mpeg");
    res.send(buffer);
  } catch (err) {
    console.error("TTS fout:", err);
    res.status(500).send("Server error");
  }
});

// ------------------------------
// 🎧 NIEUW: /speak voor de wandelapp
// ------------------------------
app.post("/speak", async (req, res) => {
  const text = req.body.text || "";
  console.log("TTS ontvangen:", text);

  try {
    const response = await fetch(
      `https://api.elevenlabs.io/v1/text-to-speech/${VOICE_ID}`,
      {
        method: "POST",
        headers: {
          "xi-api-key": API_KEY,
          "Content-Type": "application/json",
          "Accept": "audio/mpeg"
        },
        body: JSON.stringify({
          text,
          model_id: "eleven_multilingual_v2",
        }),
      }
    );

    if (!response.ok) {
      console.log("ElevenLabs error");
      return res.json({ error: true, message: "ElevenLabs error" });
    }

    const buffer = Buffer.from(await response.arrayBuffer());
    const base64 = buffer.toString("base64");

    res.json({
      ok: true,
      url: `data:audio/mpeg;base64,${base64}`,
    });
  } catch (err) {
    console.log("Server fout:", err);
    res.json({ error: true, message: "server error" });
  }
});

// ------------------------------
// START SERVER
// ------------------------------
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log("TTS server draait op poort", PORT);
});
