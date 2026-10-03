import { describe, expect, it } from 'vitest';
import { changes, isDefault, matches, plain, toFile, type Entry, type Source } from './schema';
import { chunkCount } from '../tools/Tools';

const int: Entry = { title: 'Max players', help: '', type: 'int', min: 1, max: 100, group: 'g', default: 20 };
const bool: Entry = { title: 'PvP', help: '', type: 'bool', group: 'g', default: true };
const text: Entry = { title: 'Message of the day', help: 'shown in the list', type: 'string', group: 'g', max: 10 };
const enumEntry: Entry = { title: 'Difficulty', help: '', type: 'enum', options: [['easy', 'Easy'], ['hard', 'Hard']], group: 'g' };
const password: Entry = { title: 'RCON password', help: '', type: 'password', group: 'g' };

describe('toFile matches ConfigSchema::toFile', () => {
    it('checks whole numbers and limits', () => {
        expect(toFile(int, ' 42 ')).toBe('42');
        expect(() => toFile(int, '4.2')).toThrow('Max players must be a whole number.');
        expect(() => toFile(int, '0')).toThrow("Max players can't be less than 1.");
        expect(() => toFile(int, '101')).toThrow("Max players can't be more than 100.");
    });
    it('writes booleans as true and false', () => {
        expect(toFile(bool, true)).toBe('true');
        expect(toFile(bool, 'off')).toBe('false');
    });
    it('keeps text on one line and turns \\n into a line break', () => {
        expect(toFile(text, 'a\nb')).toBe('a b');
        expect(toFile(text, 'a\\nb')).toBe('a\nb');
        expect(() => toFile(text, 'x'.repeat(11))).toThrow('at most 10 characters');
    });
    it('only accepts listed choices', () => {
        expect(toFile(enumEntry, 'hard')).toBe('hard');
        expect(() => toFile(enumEntry, 'peaceful')).toThrow('is not a choice');
    });
});

describe('changes', () => {
    const source: Source = {
        key: 'server',
        title: 'Server',
        icon: '',
        format: 'properties',
        unavailable: null,
        entries: { 'max-players': int, pvp: bool, 'rcon.password': password },
        values: { 'max-players': 20, pvp: true, 'rcon.password': '' },
    };

    it('ignores untouched values', () => {
        expect(changes(source, { ...source.values })).toEqual({});
    });
    it('lists a changed value with old and new text', () => {
        expect(changes(source, { ...source.values, 'max-players': '30' })['max-players']).toMatchObject({ old: '20', new: '30', error: null });
    });
    it('marks a bad value with its error', () => {
        expect(changes(source, { ...source.values, 'max-players': 'lots' })['max-players'].error).toBe('Max players must be a whole number.');
    });
    it('counts a typed password and rejects spaces', () => {
        expect(changes(source, { ...source.values, 'rcon.password': 'abc' })['rcon.password']).toMatchObject({ new: 'abc', error: null });
        expect(changes(source, { ...source.values, 'rcon.password': 'a b' })['rcon.password'].error).not.toBeNull();
    });
});

describe('helpers', () => {
    it('compares plain values', () => {
        expect(plain(true)).toBe('true');
        expect(plain(' 5 ')).toBe('5');
    });
    it('knows defaults', () => {
        expect(isDefault(int, '20')).toBe(true);
        expect(isDefault(int, 21)).toBe(false);
        expect(isDefault(bool, true)).toBe(true);
    });
    it('searches title, help and key', () => {
        expect(matches('motd', text, 'LIST')).toBe(true);
        expect(matches('motd', text, 'nothing')).toBe(false);
    });
    it('estimates chunks like Chunky::chunkCount', () => {
        expect(chunkCount(300, 'square')).toBe(1444);
        expect(chunkCount(300, 'circle')).toBeLessThan(1444);
    });
});
