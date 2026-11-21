
"use client";

import { useState, type FormEvent } from "react";
import {
  Users,
  TrendingUp,
  Package,
  ArrowRight,
  ShieldCheck,
} from "lucide-react";
import { send } from "@/lib/api";
import type { User } from "@/lib/types";

type LoginProps = {
  onLogin: (user: User) => void;
};

export default function Login({ onLogin }: LoginProps) {
  const [email, setEmail] = useState("admin@atlas.example");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError("");
    setBusy(true);

    try {
      const user = await send<User>("login", "POST", {
        email,
        password,
      });
      onLogin(user);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="login-page">
      <section className="login-story">
        <div className="brand">
          <span className="brand-mark">A</span>
          <span>
            ATLAS<span className="brand-lite">ERP LITE</span>
          </span>
        </div>

        <div>
          <span className="demo-pill">
            L’ESSENTIEL, BIEN PENSÉ
          </span>
          <h1>
            Une vision claire.
            <br />
            Une activité
            <br />
            <em>qui avance.</em>
          </h1>
          <p>
            Clients, stocks, ventes et factures.
            <br />
            Tout se retrouve. Tout se suit.
          </p>

          <div className="login-art">
            <div className="art-orbit" />
            <div className="art-block one">
              Clients <Users />
            </div>
            <div className="art-block two">
              Ventes <TrendingUp />
            </div>
            <div className="art-block three">
              Stocks <Package />
            </div>
          </div>
        </div>

        <small>
          Projet portfolio · PHP / Next.js / PostgreSQL
        </small>
      </section>

      <section className="login-form">
        <div className="eyebrow">
          BIENVENUE DANS VOTRE ESPACE
        </div>
        <h2>Heureux de vous retrouver.</h2>
        <p>
          Connectez-vous pour garder le cap sur votre activité.
        </p>

        <form onSubmit={submit}>
          <label>
            Adresse e-mail
            <input
              type="email"
              autoComplete="username"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </label>

          <label>
            Mot de passe
            <input
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </label>

          {error && (
            <div className="error" role="alert">
              {error}
            </div>
          )}

          <button className="button primary" disabled={busy}>
            {busy ? "Connexion…" : "Se connecter"}
            <ArrowRight size={18} />
          </button>
        </form>

        <p className="login-note">
          <ShieldCheck size={18} />
          Données fictives · Aucun paiement bancaire réel.
          <br />
          Les accès de démonstration sont dans le README.
        </p>
      </section>
    </div>
  );
}
