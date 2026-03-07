# Auction Transition Phrase Dictionary
## Built from Session 16 (Feb 27, 2026 — Mags & Slabs $2 Starts)

Analyzed segments: 1-6, 10, 50 (covering early, mid, and late auction)

---

## 1. CLOSING PHRASES (Item just sold — HIGH reliability)

These indicate the current card has been won/sold:

| Pattern | Examples | Reliability |
|---------|----------|-------------|
| `great take` | "Very great take there, James" / "Great take, Tim" / "Just a great take" | HIGH |
| `can't beat that` | "Walker. Can't beat that." / "Can't beat that at all" / "Nick, you can't beat that first" | HIGH |
| `going to [name]` | "Going to Brian" / "Going to Wyatt" / "Going up to Jesse" / "Going to GSE" / "Going to Bodan" | HIGH |
| `[N] bones` / `[N] bucks` | "three bones all day" / "six bones all day" / "sub 10 bucks" | HIGH |
| `all day` | "six bones all day" / "$9, Andy. Yeah, all day. All day." / "three bones all day" | HIGH |
| `steal` | "Getting a steal off the gate" / "Go grab yourself a seal" / "for an absolute steal" | MEDIUM |
| `amazing` / `unreal` | "Amazing sir, amazing" / "Unreal. 20 plus all day." | MEDIUM |
| `[buyer name] congrats` | "Scotty Congrats on the giveaway" / "Otani, congrats on that giveaway" | HIGH (giveaway) |
| `doing it again` | "Caleb doing it again, doing it again" | MEDIUM |
| `there you go` | "There you go. Conor." | MEDIUM |

## 2. OPENING/TRANSITION PHRASES (Next card being introduced — HIGH reliability)

| Pattern | Examples | Reliability |
|---------|----------|-------------|
| `how about a [player/card]` | "How about a all aces smolty?" / "How about a cosmic refractor Paul Skiens?" / "How about a Salvi?" | VERY HIGH |
| `here's a [nice/fun] one` | "Here's a nice one." / "Here's a fun one." / "Here's another nice Jordana" | HIGH |
| `about a [card]` | "About a PCA Pink to 350?" / "About your NL rookie of the year" | HIGH |
| `let's go` / `let's start` | "Let's start with a bang" / "Let's go here" | MEDIUM |
| `bang` | "Bang. There's a nice one." | MEDIUM |
| `[numbered] to [N]` (first mention) | "purple to 250" / "black to 399" / "blue to 150" | MEDIUM (card description, not transition alone) |

## 3. PRICE MENTIONS (Match against line item original_item_price)

Price is spoken in several formats:

| Format | Examples | Notes |
|--------|----------|-------|
| `[N] dollars` | "nine dollars" / "two dollars" / "four dollars" | Common for exact prices |
| `[N] bucks` | "five bucks" / "sub 10 bucks" | Casual price reference |
| `[N] bones` | "three bones" / "six bones" / "two bones" | Slang for dollars |
| `$[N]` / `live at $[N]` | "live at $2" / "$4" / "$9" | Direct price callout |
| `sub [N]` | "sub $5" / "sub 10 dollar card" / "Sub-cost of grading" | Price is BELOW the number |
| `for [N]` | "for three bones" / "for $11" / "for sub 10 bucks" | Price after sale |

**Key insight**: Prices near closing phrases are the SALE PRICE. Prices near opening descriptions may be the starting bid ($2) or numbered limit.

## 4. GIVEAWAY INDICATORS

| Pattern | Examples | Reliability |
|---------|----------|-------------|
| `giveaway` | "buyers giveaway tonight" / "fire giveaway" / "congrats on the giveaway" | VERY HIGH |
| `free card` | "Free card, low shipping costs" | HIGH |
| `give away` (two words) | "Give the buyers get away for two dollars" | HIGH |
| `lucky buyer` | "one lucky buyer here" | HIGH |
| `ultraviolet [player]` + context | "ultraviolet Pedro" (often the giveaway card) | MEDIUM |

## 5. STRUCTURAL PATTERNS

### Card Description Template
The auctioneer follows a consistent pattern when presenting a card:
```
[Intro phrase] + [Player Name] + [Card Style/Parallel] + [Numbered info]
```
Examples:
- "How about a rookie sapphire of Junior Caminero"
- "Cosmic refractor Paul Skiens"
- "Kyle Tucker on the cosmic refractor"
- "Brent Rooker game used at a tier one. That's to 149."

### Item Flow (one complete auction item):
1. **INTRO**: "How about a..." / "Here's a nice one..."
2. **DESCRIPTION**: Player name + style + numbered info
3. **REITERATION**: Player name repeated 1-3 more times
4. **COMMENTARY**: Chat interaction, hype, comparisons
5. **CLOSE**: Price mention + buyer name or closing phrase
6. **[TRANSITION]**: Move to next card

### Timing Observations
- Each card takes ~20-45 seconds on average
- Auctioneer DESCRIBES the card ~20-60 seconds BEFORE it sells
- The `estimated_at` timestamp from parsed records LEADS the `order_placed_at` by this amount
- Giveaways happen every ~5 minutes (very consistent: ~5m13s to ~5m16s)
- Between each giveaway: ~8-10 auction items

### Duplicate Record Pattern
When the same player appears 2-4 times in sequence, it's the auctioneer:
1. Introducing the card (first mention)
2. Reiterating/describing it (middle mentions)
3. Confirming the sale or commenting (last mention)

These should be COLLAPSED into a single card mention for matching.

## 6. FALSE POSITIVE PATTERNS (NOT item boundaries)

| Pattern | Why it's misleading |
|---------|-------------------|
| Player name mentioned in chat response | "No, Connor is not out yet" — not a new card |
| Future stream references | "I got streams prepped for next Friday" — not a card |
| "I got [player]" / "I do got [player]" | Answering viewer question, not presenting a card |
| "Check the shop" / "pre-bids" | Shop/pre-bid references, not live auction items |
| Back wall / pre-bid items | "We'll throw a J-Dom on that back wall" — not current auction |

## 7. BACK WALL / SPECIAL AUCTIONS

The auctioneer occasionally runs longer auctions (20-30 seconds) for premium items:
- "Go up 20 seconds" / "Up and running"
- "We'll do one of those pre-bid auctions at 100"
- "Going up 20 seconds, up and running, go at it"

These are NOT the normal "sudden death" items and may have higher prices.
