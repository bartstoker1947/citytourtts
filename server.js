import express from "express";
import fetch from "node-fetch";

const app = express();
const PORT = process.env.PORT || 3000;

// 🔑 Jouw echte ElevenLabs API-key
const API_KEY = "sk_f25ed488961828aa07748dd10eaab87bdbf99cc360e1573b";

// 🗣️ Stem-ID (kan later per taal worden aangepast)
const VOICE_ID = "21m00Tcm4TlvDq8ikWAM"; // Clyde (Engels), werkt voor test

// Statische bestanden (voor test of lokaal gebruik)
app.use(express.static("."));

// 🎧 Route voor tekst-naar-spraak
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
        model_id: "eleven_multilingual_v2", // ondersteunt NL, DE, FR, IT
        voice_settings: { stability: 0.4, similarity_boost: 0.9 },
      }),
    });

    if (!response.ok) {
      const errText = await response.text();
      console.error("Fout van ElevenLabs:", errText);
      return res.status(500).send("Fout bij ophalen audio van ElevenLabs.");
    }

    // Zet de audio terug naar de browser
    const buffer = Buffer.from(await response.arrayBuffer());
    res.set("Content-Type", "audio/mpeg");
    res.send(buffer);

  } catch (error) {
    console.error("Serverfout:", error);
    res.status(500).send("Interne serverfout.");
  }
});

app.listen(PORT, () => console.log(`✅ Server draait op poort ${PORT}`));
