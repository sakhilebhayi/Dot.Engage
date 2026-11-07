---
name: Dot.Engage, The Margin
version: 1
colors:
  desk: "#eceeec"
  paper: "#fcfcfb"
  ink: "#15181b"
  muted: "#5a6168"
  wax: "#a3262a"
  pen-blue: "#234a8f"
  pen-green: "#1b6b5f"
  pen-plum: "#7a3a74"
  pen-graphite: "#4f555c"
typography:
  display: { fontFamily: Public Sans, fontWeight: 600 }
  clause: { fontFamily: Literata, fontWeight: 400 }
  figure: { fontFamily: IBM Plex Mono, fontWeight: 400 }
---

# Dot.Engage: The Margin

## Brief

Dot.Engage is where two or more businesses meet, talk a contract through, change it, approve it and sign it, in one place.
Audience: business owners, contract managers and lawyers on the host side; clients, suppliers and partners (often with no
account) on the other. The job of the interface is trust: nobody should wonder what is agreed, what is still open, who has
to act next, or whether a signature is real. Operate mode for the workspace, with one Persuade moment (the invitation) and
one Experience moment (the seal).

## Concept card

- **Idea:** an agreement is a page with a margin. The document sits in the middle and everything people say about it is
  written in the margin beside the clause it concerns.
- **Metaphor:** the signed instrument: numbered clauses, notes in the margin, initials on each page, an execution page, a seal.
- **Anchor:** real contract practice. Negotiation happens as marginal notes, changes are tracked against a clause, and the
  document is only finished when the execution page carries every signature and the seal.
- **Signature moment:** the seal. When the last signature lands, a seal presses onto the execution page (a ring made from
  the engagement reference and the first characters of the document hash), the margin goes quiet, and the verify link appears.
- **Emotion in 3 seconds:** calm, exact, official without being cold.
- **Motion verb:** press and settle. Things go down onto the page (120 ms in, 200 ms settle, 1.5 px of recoil), they do not float.
- **Screenshot:** the workspace: contract page centred, three notes hung in the margin on leader lines to their clauses, the
  six-stage rail down the left edge with "Negotiate" current.

## Rules this produces

1. **Layout:** a left stage rail (Engage, Discuss, Negotiate, Approve, Sign, Complete), the document column in the middle,
   the margin on the right. Everything that is "about" the document lives in the margin, hung on a leader line.
2. **No cards.** Content is ruled lists and sections on the desk; the paper surface is reserved for the document itself and
   for things that really are sheets (the signing sheet, a thread).
3. **Every party has a pen.** Each party gets one of four ink colours; its people's initials, notes and signature lines use it.
   Colour means "who", never "how urgent".
4. **State is words first.** A one-line "Next" strip under the title says what the engagement needs, from recorded facts.
5. **Wax is reserved** for the seal, for refusal (decline, cancel) and nothing else. Primary actions are solid ink.
6. **Figures are mono.** References, hashes, counts, times.

## Colour

Light is "day desk": desk `#eceeec`, paper `#fcfcfb`, ink `#15181b`, muted `#5a6168`, line ink at 14 percent. Dark is "night desk":
desk `#111416`, paper `#191d20`, ink `#eceeed`, muted `#9aa2a9`. Pens: blue-black `#234a8f`, verdigris `#1b6b5f`, plum `#7a3a74`,
graphite `#4f555c` (lightened in dark). Wax `#a3262a` (light) / `#e0666a` (dark). Status: confirmed `#1d6a46`, waiting `#8a5a00`.
Not used: cream with terracotta, near-black with acid accent, purple gradients, glass.

## Type

Public Sans (variable, 400 to 700) for the interface: it was drawn for government forms, which is the right register.
Literata for clause text (proposed wording, quoted clauses, the agreement title block): a book face for sustained reading.
IBM Plex Mono for references, hashes, times and counts. Body 15/22, labels 13/18, display 30/34 at weight 600 with -0.01em.
No tracked uppercase eyebrows over headings.

## Shape, depth, motion

Radius 3 px on controls; documents and sheets are square. No shadows except one hairline lift under a sheet that sits above
the desk (signing sheet, dialogs). Motion is `cubic-bezier(.2,.7,.2,1)`, 120 to 220 ms; nothing animates layout properties;
reduced motion removes everything but the colour change on state.

## Hover, focus, press

Primary and secondary buttons: the label slides 1 px up and the fill inverts to outline on hover; press sinks 1 px. Rows:
the leader marker fills. Links in text keep an underline. Focus: 2 px ink ring with 2 px desk offset, always visible.

## Idle state

A quiet product: it stays still at rest by decision. Life comes from state (live meeting dot, "waiting for X" timers) and from
the seal and press motion, not from decoration.

## Copy

Plain, specific, no filler verbs. Say what happened and what to do. No em or en dashes in visible text.
