#!/usr/bin/env python3
"""Deterministic regression for the production SSH channel reader (no Paramiko required)."""
import importlib.util
import pathlib
import unittest
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('host_transport',ROOT/'core/Connector/agents/host_transport.py')
transport=importlib.util.module_from_spec(spec);spec.loader.exec_module(transport)

class Channel:
    closed=False
    def __init__(self,frames,exit_code=0,never_eof=False):
        self.frames=frames;self.tick=0;self.exit_code=exit_code;self.never_eof=never_eof
        self.stdout=b'';self.stderr=b''
        self.advance()
    def advance(self):
        if self.tick<len(self.frames):
            out,err=self.frames[self.tick];self.stdout+=out;self.stderr+=err
        self.tick+=1
    @property
    def eof_received(self):return not self.never_eof and self.tick>len(self.frames)
    def recv_ready(self):return bool(self.stdout)
    def recv_stderr_ready(self):return bool(self.stderr)
    def recv(self,n):data,self.stdout=self.stdout[:n],self.stdout[n:];return data
    def recv_stderr(self,n):data,self.stderr=self.stderr[:n],self.stderr[n:];return data
    def exit_status_ready(self):return True
    def recv_exit_status(self):return self.exit_code

class Tests(unittest.TestCase):
    def collect(self,ch,limit=1000):
        with patch.object(transport.time,'sleep',side_effect=lambda _:ch.advance()),patch.object(transport.time,'monotonic',side_effect=lambda:ch.tick):
            return transport.read_ssh_channel(ch,limit)
    def test_exit_before_late_stdout(self):
        self.assertEqual(self.collect(Channel([(b'{"x":',b''),(b'1}',b'')])),{'x':1})
    def test_nonzero(self):
        with self.assertRaises(transport.Failure) as error:self.collect(Channel([(b'{}',b'')],exit_code=1))
        self.assertEqual(error.exception.code,'connector_protocol_error')
    def test_no_eof(self):
        with self.assertRaises(transport.Failure) as error:self.collect(Channel([(b'{}',b'')],never_eof=True))
        self.assertEqual(error.exception.code,'connector_transport_failed')
    def test_stderr_counts_toward_limit(self):
        with self.assertRaises(transport.Failure) as error:self.collect(Channel([(b'{}',b'x'*100)]),limit=100)
        self.assertEqual(error.exception.code,'connector_protocol_error')

if __name__=='__main__':unittest.main()
