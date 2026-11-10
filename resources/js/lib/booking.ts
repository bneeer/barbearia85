export type BookingDraft = {
    service_id: number;
    preference: number | 'any' | null;
    barber_id: number | null;
    date: string;
    time: string;
};

export function reconcileDrafts(
    ids: number[],
    drafts: BookingDraft[],
): BookingDraft[] {
    return ids.map(
        (id) =>
            drafts.find((draft) => draft.service_id === id) ?? {
                service_id: id,
                preference: null,
                barber_id: null,
                date: '',
                time: '',
            },
    );
}

export function endTime(time: string, duration: number): string {
    const [hour, minute] = time.split(':').map(Number);
    const end = hour * 60 + minute + duration;

    return `${String(Math.floor(end / 60)).padStart(2, '0')}:${String(end % 60).padStart(2, '0')}`;
}

export function overlapsDraft(
    candidate: Pick<BookingDraft, 'date' | 'time'>,
    duration: number,
    other: Pick<BookingDraft, 'date' | 'time'>,
    otherDuration: number,
): boolean {
    return Boolean(
        candidate.date &&
        candidate.time &&
        other.time &&
        candidate.date === other.date &&
        candidate.time < endTime(other.time, otherDuration) &&
        endTime(candidate.time, duration) > other.time,
    );
}
